<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


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
            return $this->response->json(['error' => $e->getMessage()])->withStatus(400);
        } catch (\Throwable $e) {
            error_log('[UPLOAD_ERROR] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->response->json(['error' => 'Upload processing failed'])->withStatus(500);
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
