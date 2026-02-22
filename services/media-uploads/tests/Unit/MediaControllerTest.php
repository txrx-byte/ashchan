<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\MediaController;
use App\Service\MediaService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit tests for MediaController.
 *
 * Covers: upload() error paths (no file, invalid file, runtime exception,
 * internal error), ban() validation (missing hash, empty hash, valid hash).
 */
final class MediaControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MediaService&Mockery\MockInterface $mediaService;
    private HttpResponse&Mockery\MockInterface $httpResponse;
    private MediaController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaService = Mockery::mock(MediaService::class);
        $this->httpResponse = Mockery::mock(HttpResponse::class);
        $this->controller = new MediaController($this->mediaService, $this->httpResponse);
    }

    // ── Helper to build mock responses ───────────────────────────────

    private function expectJsonResponse(mixed $data, int $statusCode = 200): ResponseInterface
    {
        $response = Mockery::mock(ResponseInterface::class);

        if ($statusCode !== 200) {
            $innerResponse = Mockery::mock(ResponseInterface::class);
            $this->httpResponse->shouldReceive('json')
                ->with($data)
                ->once()
                ->andReturn($innerResponse);
            $innerResponse->shouldReceive('withStatus')
                ->with($statusCode)
                ->once()
                ->andReturn($response);
        } else {
            $this->httpResponse->shouldReceive('json')
                ->with($data)
                ->once()
                ->andReturn($response);
        }

        return $response;
    }

    // ── upload(): no file ────────────────────────────────────────────

    public function testUploadReturnsErrorWhenNoFile(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('file')
            ->with('upfile')
            ->once()
            ->andReturn(null);

        $expected = $this->expectJsonResponse(['error' => 'No file uploaded']);

        $result = $this->controller->upload($request);
        $this->assertSame($expected, $result);
    }

    // ── upload(): array of files ─────────────────────────────────────

    public function testUploadReturnsErrorWhenMultipleFiles(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $file1 = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);
        $file2 = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);

        $request->shouldReceive('file')
            ->with('upfile')
            ->once()
            ->andReturn([$file1, $file2]);

        $expected = $this->expectJsonResponse(['error' => 'No valid file uploaded']);

        $result = $this->controller->upload($request);
        $this->assertSame($expected, $result);
    }

    // ── upload(): invalid file ───────────────────────────────────────

    public function testUploadReturnsErrorWhenFileInvalid(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $file = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);
        $file->shouldReceive('isValid')
            ->once()
            ->andReturn(false);

        $request->shouldReceive('file')
            ->with('upfile')
            ->once()
            ->andReturn($file);

        $expected = $this->expectJsonResponse(['error' => 'No valid file uploaded']);

        $result = $this->controller->upload($request);
        $this->assertSame($expected, $result);
    }

    // ── upload(): file path is false ─────────────────────────────────

    public function testUploadReturnsErrorWhenGetRealPathFails(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $file = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);
        $file->shouldReceive('isValid')->once()->andReturn(true);
        $file->shouldReceive('getRealPath')->once()->andReturn(false);

        $request->shouldReceive('file')
            ->with('upfile')
            ->once()
            ->andReturn($file);

        $expected = $this->expectJsonResponse(['error' => 'Could not get file path']);

        $result = $this->controller->upload($request);
        $this->assertSame($expected, $result);
    }

    // ── upload(): RuntimeException from service ──────────────────────

    public function testUploadReturns400OnRuntimeException(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $file = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);
        $file->shouldReceive('isValid')->once()->andReturn(true);
        $file->shouldReceive('getRealPath')->once()->andReturn('/tmp/test.jpg');
        $file->shouldReceive('getClientFilename')->once()->andReturn('test.jpg');
        $file->shouldReceive('getClientMediaType')->once()->andReturn('image/jpeg');
        $file->shouldReceive('getSize')->once()->andReturn(1024);

        $request->shouldReceive('file')
            ->with('upfile')
            ->once()
            ->andReturn($file);

        $this->mediaService->shouldReceive('processUpload')
            ->once()
            ->andThrow(new \RuntimeException('File too large'));

        $expected = $this->expectJsonResponse(['error' => 'File too large'], 400);

        $result = $this->controller->upload($request);
        $this->assertSame($expected, $result);
    }

    // ── upload(): unexpected exception ───────────────────────────────

    public function testUploadReturns500OnUnexpectedException(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $file = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);
        $file->shouldReceive('isValid')->once()->andReturn(true);
        $file->shouldReceive('getRealPath')->once()->andReturn('/tmp/test.jpg');
        $file->shouldReceive('getClientFilename')->once()->andReturn('test.jpg');
        $file->shouldReceive('getClientMediaType')->once()->andReturn('image/jpeg');
        $file->shouldReceive('getSize')->once()->andReturn(1024);

        $request->shouldReceive('file')
            ->with('upfile')
            ->once()
            ->andReturn($file);

        $this->mediaService->shouldReceive('processUpload')
            ->once()
            ->andThrow(new \Exception('DB connection failed'));

        $expected = $this->expectJsonResponse(['error' => 'Upload processing failed'], 500);

        $result = $this->controller->upload($request);
        $this->assertSame($expected, $result);
    }

    // ── upload(): success ────────────────────────────────────────────

    public function testUploadReturnsMetadataOnSuccess(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $file = Mockery::mock(\Hyperf\HttpMessage\Upload\UploadedFile::class);
        $file->shouldReceive('isValid')->once()->andReturn(true);
        $file->shouldReceive('getRealPath')->once()->andReturn('/tmp/test.jpg');
        $file->shouldReceive('getClientFilename')->once()->andReturn('photo.jpg');
        $file->shouldReceive('getClientMediaType')->once()->andReturn('image/jpeg');
        $file->shouldReceive('getSize')->once()->andReturn(2048);

        $request->shouldReceive('file')
            ->with('upfile')
            ->once()
            ->andReturn($file);

        $metadata = [
            'media_id' => 1,
            'media_url' => 'http://minio:9000/ashchan/2026/01/01/abc.jpg',
            'thumb_url' => 'http://minio:9000/ashchan/2026/01/01/abc_thumb.jpg',
            'media_filename' => 'photo.jpg',
            'media_size' => 2048,
            'media_dimensions' => '800x600',
            'media_hash' => 'sha256hash',
        ];

        $this->mediaService->shouldReceive('processUpload')
            ->with('/tmp/test.jpg', 'photo.jpg', 'image/jpeg', 2048)
            ->once()
            ->andReturn($metadata);

        $expected = $this->expectJsonResponse($metadata);

        $result = $this->controller->upload($request);
        $this->assertSame($expected, $result);
    }

    // ── ban(): missing hash ──────────────────────────────────────────

    public function testBanReturnsErrorWhenHashMissing(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('input')
            ->with('hash')
            ->once()
            ->andReturn(null);

        $expected = $this->expectJsonResponse(['error' => 'Hash required']);

        $result = $this->controller->ban($request);
        $this->assertSame($expected, $result);
    }

    public function testBanReturnsErrorWhenHashEmpty(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('input')
            ->with('hash')
            ->once()
            ->andReturn('');

        $expected = $this->expectJsonResponse(['error' => 'Hash required']);

        $result = $this->controller->ban($request);
        $this->assertSame($expected, $result);
    }

    public function testBanReturnsErrorWhenHashNotString(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('input')
            ->with('hash')
            ->once()
            ->andReturn(123);

        $expected = $this->expectJsonResponse(['error' => 'Hash required']);

        $result = $this->controller->ban($request);
        $this->assertSame($expected, $result);
    }

    // ── ban(): success ───────────────────────────────────────────────

    public function testBanCallsServiceAndReturnsOk(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('input')
            ->with('hash')
            ->once()
            ->andReturn('abc123hash');

        $this->mediaService->shouldReceive('banHash')
            ->with('abc123hash')
            ->once();

        $expected = $this->expectJsonResponse(['status' => 'ok']);

        $result = $this->controller->ban($request);
        $this->assertSame($expected, $result);
    }
}
