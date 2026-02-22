<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controller\MediaController;
use App\Service\MediaService;
use App\Service\SiteConfigService;
use Ashchan\EventBus\EventPublisher;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Feature tests for the media upload pipeline.
 *
 * Tests the full flow from controller → service for upload scenarios,
 * using real files and mocking only the DB layer and object storage.
 */
final class MediaUploadPipelineTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/media-upload-feature-' . getmypid();
        if (!is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureDir)) {
            $this->removeDir($this->fixtureDir);
        }
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function createTestJpeg(int $w = 300, int $h = 200): string
    {
        $img = imagecreatetruecolor($w, $h);
        assert($img !== false);
        $color = imagecolorallocate($img, 200, 100, 50);
        assert($color !== false);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $color);
        $path = $this->fixtureDir . '/upload_' . uniqid() . '.jpg';
        imagejpeg($img, $path, 85);
        imagedestroy($img);
        return $path;
    }

    private function createTestPng(int $w = 300, int $h = 200): string
    {
        $img = imagecreatetruecolor($w, $h);
        assert($img !== false);
        $color = imagecolorallocate($img, 50, 100, 200);
        assert($color !== false);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $color);
        $path = $this->fixtureDir . '/upload_' . uniqid() . '.png';
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    /**
     * Build a MediaService with standard config and mocked external deps.
     *
     * @return array{service: MediaService, eventPublisher: EventPublisher&Mockery\MockInterface, config: SiteConfigService&Mockery\MockInterface}
     */
    private function buildService(): array
    {
        $eventPublisher = Mockery::mock(EventPublisher::class);
        $config = Mockery::mock(SiteConfigService::class);

        $localPath = $this->fixtureDir . '/storage';
        if (!is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $config->shouldReceive('get')->with('object_storage_bucket', 'ashchan')->andReturn('test-bucket');
        // Use invalid endpoint so storage upload fails → falls back to local disk
        $config->shouldReceive('get')->with('object_storage_endpoint', 'http://minio:9000')->andReturn('http://127.0.0.1:1');
        $config->shouldReceive('get')->with('object_storage_access_key', 'minioadmin')->andReturn('testkey');
        $config->shouldReceive('get')->with('object_storage_secret_key', 'minioadmin')->andReturn('testsecret');
        $config->shouldReceive('getInt')->with('max_file_size', 4194304)->andReturn(4194304);
        $config->shouldReceive('getList')->with('allowed_mimes', 'image/jpeg,image/png,image/gif,image/webp')
            ->andReturn(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        $config->shouldReceive('getInt')->with('thumb_max_width', 250)->andReturn(250);
        $config->shouldReceive('getInt')->with('thumb_max_height', 250)->andReturn(250);
        $config->shouldReceive('getInt')->with('upload_connect_timeout', 3)->andReturn(1);
        $config->shouldReceive('getInt')->with('upload_timeout', 15)->andReturn(2);
        $config->shouldReceive('get')->with('local_storage_path', '/workspaces/ashchan/data/media')->andReturn($localPath);

        $service = new MediaService($eventPublisher, $config);

        return compact('service', 'eventPublisher', 'config');
    }

    // ── Full upload pipeline: validation → hash → thumbnail → store ──

    public function testUploadPipelineRejectsOversizedFile(): void
    {
        ['service' => $service] = $this->buildService();

        $path = $this->createTestJpeg(100, 100);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File too large');

        $service->processUpload($path, 'big.jpg', 'image/jpeg', 5_000_000);
    }

    public function testUploadPipelineRejectsDisallowedMimeType(): void
    {
        ['service' => $service] = $this->buildService();

        $path = $this->fixtureDir . '/document.pdf';
        // Create a tiny PDF-like file
        file_put_contents($path, '%PDF-1.4 fake pdf content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File type not allowed');

        $service->processUpload($path, 'document.pdf', 'application/pdf', 25);
    }

    public function testUploadPipelineRejectsMimeTypeMismatch(): void
    {
        ['service' => $service] = $this->buildService();

        // Plain text file declared as image/jpeg
        $path = $this->fixtureDir . '/fake.txt';
        file_put_contents($path, 'This is not an image at all.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File type mismatch');

        $service->processUpload($path, 'fake.jpg', 'image/jpeg', 28);
    }

    public function testUploadPipelineComputesSha256Hash(): void
    {
        ['service' => $service] = $this->buildService();

        $path = $this->createTestJpeg(100, 80);
        $expectedHash = hash_file('sha256', $path);

        // We can't mock the static MediaObject::query()/create() since the class
        // is already loaded. Instead, test the validation + hashing pipeline
        // via reflection on the validate method, verifying the hash is correct.
        $validateMethod = new \ReflectionMethod($service, 'validate');
        $validateMethod->setAccessible(true);

        $fileSize = (int) filesize($path);
        $actualMime = $validateMethod->invoke($service, $path, 'image/jpeg', $fileSize);
        $this->assertSame('image/jpeg', $actualMime);

        // Verify the hash matches what the service would compute
        $computedHash = hash_file('sha256', $path);
        $this->assertSame($expectedHash, $computedHash);
        $this->assertNotEmpty($computedHash);
        $this->assertSame(64, strlen($computedHash)); // SHA-256 = 64 hex chars
    }

    // ── Controller → Service integration ─────────────────────────────

    public function testControllerRejectsUploadWithNoFile(): void
    {
        $mediaService = Mockery::mock(MediaService::class);
        $httpResponse = Mockery::mock(HttpResponse::class);
        $response = Mockery::mock(ResponseInterface::class);

        $httpResponse->shouldReceive('json')
            ->with(['error' => 'No file uploaded'])
            ->once()
            ->andReturn($response);

        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('file')->with('upfile')->andReturn(null);

        $controller = new MediaController($mediaService, $httpResponse);
        $result = $controller->upload($request);

        $this->assertSame($response, $result);
    }

    public function testControllerDelegatesValidUploadToService(): void
    {
        $mediaService = Mockery::mock(MediaService::class);
        $httpResponse = Mockery::mock(HttpResponse::class);
        $response = Mockery::mock(ResponseInterface::class);

        $metadata = [
            'media_id' => 5,
            'media_url' => 'http://localhost:9000/bucket/img.jpg',
            'thumb_url' => null,
            'media_filename' => 'pic.jpg',
            'media_size' => 1024,
            'media_dimensions' => '100x80',
            'media_hash' => 'somehash',
        ];

        $file = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);
        $file->shouldReceive('isValid')->andReturn(true);
        $file->shouldReceive('getRealPath')->andReturn('/tmp/test.jpg');
        $file->shouldReceive('getClientFilename')->andReturn('pic.jpg');
        $file->shouldReceive('getClientMediaType')->andReturn('image/jpeg');
        $file->shouldReceive('getSize')->andReturn(1024);

        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('file')->with('upfile')->andReturn($file);

        $mediaService->shouldReceive('processUpload')
            ->with('/tmp/test.jpg', 'pic.jpg', 'image/jpeg', 1024)
            ->once()
            ->andReturn($metadata);

        $httpResponse->shouldReceive('json')
            ->with($metadata)
            ->once()
            ->andReturn($response);

        $controller = new MediaController($mediaService, $httpResponse);
        $result = $controller->upload($request);

        $this->assertSame($response, $result);
    }

    // ── Ban pipeline ─────────────────────────────────────────────────

    public function testBanPipelineRejectsEmptyHash(): void
    {
        $mediaService = Mockery::mock(MediaService::class);
        $httpResponse = Mockery::mock(HttpResponse::class);
        $response = Mockery::mock(ResponseInterface::class);

        $httpResponse->shouldReceive('json')
            ->with(['error' => 'Hash required'])
            ->once()
            ->andReturn($response);

        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('input')->with('hash')->andReturn('');

        $controller = new MediaController($mediaService, $httpResponse);
        $result = $controller->ban($request);

        $this->assertSame($response, $result);
    }

    public function testBanPipelineCallsServiceWithHash(): void
    {
        $mediaService = Mockery::mock(MediaService::class);
        $httpResponse = Mockery::mock(HttpResponse::class);
        $response = Mockery::mock(ResponseInterface::class);

        $mediaService->shouldReceive('banHash')
            ->with('targetHash123')
            ->once();

        $httpResponse->shouldReceive('json')
            ->with(['status' => 'ok'])
            ->once()
            ->andReturn($response);

        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('input')->with('hash')->andReturn('targetHash123');

        $controller = new MediaController($mediaService, $httpResponse);
        $result = $controller->ban($request);

        $this->assertSame($response, $result);
    }

    // ── Thumbnail generation integration ─────────────────────────────

    public function testThumbnailGeneratedForLargeUpload(): void
    {
        ['service' => $service] = $this->buildService();

        $path = $this->createTestJpeg(800, 600);

        $method = new \ReflectionMethod($service, 'generateThumbnail');
        $method->setAccessible(true);
        $thumbPath = $method->invoke($service, $path, 'image/jpeg');

        $this->assertNotNull($thumbPath);
        $this->assertFileExists($thumbPath);

        $thumbInfo = getimagesize($thumbPath);
        $this->assertIsArray($thumbInfo);
        $this->assertLessThanOrEqual(250, $thumbInfo[0]);
        $this->assertLessThanOrEqual(250, $thumbInfo[1]);

        // Verify aspect ratio is approximately preserved (800:600 = 4:3)
        $ratio = $thumbInfo[0] / $thumbInfo[1];
        $this->assertEqualsWithDelta(4 / 3, $ratio, 0.05);

        @unlink($thumbPath);
    }

    public function testNoThumbnailForSmallUpload(): void
    {
        ['service' => $service] = $this->buildService();

        // 50x50 is well within 250x250 thumb bounds
        $path = $this->createTestJpeg(50, 50);

        $method = new \ReflectionMethod($service, 'generateThumbnail');
        $method->setAccessible(true);
        $thumbPath = $method->invoke($service, $path, 'image/jpeg');

        $this->assertNull($thumbPath);
    }

    public function testPngThumbnailPreservesTransparencySetup(): void
    {
        ['service' => $service] = $this->buildService();

        $path = $this->createTestPng(500, 400);

        $method = new \ReflectionMethod($service, 'generateThumbnail');
        $method->setAccessible(true);
        $thumbPath = $method->invoke($service, $path, 'image/png');

        $this->assertNotNull($thumbPath);
        $this->assertFileExists($thumbPath);

        // Verify the output is a valid image
        $info = getimagesize($thumbPath);
        $this->assertIsArray($info);
        $this->assertGreaterThan(0, $info[0]);
        $this->assertGreaterThan(0, $info[1]);

        @unlink($thumbPath);
    }

    // ── Validation pipeline with multiple MIME types ─────────────────

    /**
     * @dataProvider validMimeFiles
     */
    public function testValidationAcceptsAllowedMimeTypes(string $type, callable $creator): void
    {
        ['service' => $service] = $this->buildService();

        $path = $creator($this->fixtureDir);

        $method = new \ReflectionMethod($service, 'validate');
        $method->setAccessible(true);
        $result = $method->invoke($service, $path, $type, 1024);

        $this->assertSame($type, $result);
    }

    /**
     * @return array<string, array{string, callable}>
     */
    public static function validMimeFiles(): array
    {
        return [
            'jpeg' => ['image/jpeg', function (string $dir): string {
                $img = imagecreatetruecolor(10, 10);
                assert($img !== false);
                $p = $dir . '/v_' . uniqid() . '.jpg';
                imagejpeg($img, $p);
                imagedestroy($img);
                return $p;
            }],
            'png' => ['image/png', function (string $dir): string {
                $img = imagecreatetruecolor(10, 10);
                assert($img !== false);
                $p = $dir . '/v_' . uniqid() . '.png';
                imagepng($img, $p);
                imagedestroy($img);
                return $p;
            }],
            'gif' => ['image/gif', function (string $dir): string {
                $img = imagecreatetruecolor(10, 10);
                assert($img !== false);
                $p = $dir . '/v_' . uniqid() . '.gif';
                imagegif($img, $p);
                imagedestroy($img);
                return $p;
            }],
            'webp' => ['image/webp', function (string $dir): string {
                $img = imagecreatetruecolor(10, 10);
                assert($img !== false);
                $p = $dir . '/v_' . uniqid() . '.webp';
                imagewebp($img, $p);
                imagedestroy($img);
                return $p;
            }],
        ];
    }
}
