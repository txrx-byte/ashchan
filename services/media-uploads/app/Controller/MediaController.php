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
        if (!$file || !$file->isValid()) {
            return $this->response->json(['error' => 'No valid file uploaded'], 400);
        }

        try {
            $meta = $this->mediaService->processUpload(
                $file->getRealPath(),
                $file->getClientFilename(),
                $file->getClientMediaType(),
                $file->getSize()
            );

            return $this->response->json($meta, 201);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Upload processing failed'], 500);
        }
    }

    /** POST /api/v1/media/ban – Ban a file hash (moderation) */
    #[RequestMapping(path: 'ban', methods: ['POST'])]
    public function ban(RequestInterface $request): ResponseInterface
    {
        $hash = (string) $request->input('hash', '');
        if (empty($hash)) {
            return $this->response->json(['error' => 'Hash required'], 400);
        }

        $this->mediaService->banHash($hash);
        return $this->response->json(['status' => 'ok']);
    }
}
