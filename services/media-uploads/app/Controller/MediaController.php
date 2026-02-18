<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\MediaService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/v1/media')]
final class MediaController
{
    public function __construct(
        private MediaService $mediaService,
        private HttpResponse $response,
    ) {}

    /** POST /api/v1/media/upload – Upload a file */
    #[RequestMapping(path: 'upload', methods: ['POST'])]
    public function upload(RequestInterface $request): ResponseInterface
    {
        $file = $request->file('upfile');
        if (!$file) {
            return $this->response->json(['error' => 'No file uploaded']);
        }
        if (is_array($file) || !$file->isValid()) {
            return $this->response->json(['error' => 'No valid file uploaded']);
        }

        try {
            $path = $file->getRealPath();
            if ($path === false) {
                return $this->response->json(['error' => 'Could not get file path']);
            }
            $meta = $this->mediaService->processUpload(
                $path,
                (string) $file->getClientFilename(),
                (string) $file->getClientMediaType(),
                (int) $file->getSize()
            );

            return $this->response->json($meta);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Upload processing failed']);
        }
    }

    /** POST /api/v1/media/ban – Ban a file hash (moderation) */
    #[RequestMapping(path: 'ban', methods: ['POST'])]
    public function ban(RequestInterface $request): ResponseInterface
    {
        $hash = $request->input('hash');
        if (!is_string($hash) || empty($hash)) {
            return $this->response->json(['error' => 'Hash required']);
        }

        $this->mediaService->banHash($hash);
        return $this->response->json(['status' => 'ok']);
    }
}
