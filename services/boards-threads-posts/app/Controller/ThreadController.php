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

use App\Model\Thread;
use App\Service\BoardService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

final class ThreadController
{
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /** GET /api/v1/boards/{slug}/threads?page=1 */
    public function index(RequestInterface $request, string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $pageInput = $request->query('page', '1');
        $page = max(1, is_numeric($pageInput) ? (int) $pageInput : 1);
        $data = $this->boardService->getThreadIndex($board, $page);
        return $this->response->json($data);
    }

    /** GET /api/v1/boards/{slug}/catalog */
    public function catalog(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $data = $this->boardService->getCatalog($board);
        return $this->response->json(['threads' => $data]);
    }

    /** GET /api/v1/boards/{slug}/archive */
    public function archive(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $data = $this->boardService->getArchive($board);
        return $this->response->json(['archived_threads' => $data]);
    }

    /** GET /api/v1/boards/{slug}/threads/{id} */
    public function show(string $slug, int $id): ResponseInterface
    {
        $data = $this->boardService->getThread($id);
        if (!$data) {
            return $this->response->json(['error' => 'Thread not found'])->withStatus(404);
        }
        return $this->response->json($data);
    }

    /** POST /api/v1/boards/{slug}/threads – Create new thread */
    public function create(RequestInterface $request, string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }

        $input = $request->all();
        $name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
        $email = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
        $subject = isset($input['sub']) && is_string($input['sub']) ? $input['sub'] : '';
        $content = isset($input['com']) && is_string($input['com']) ? $input['com'] : '';
        $password = isset($input['pwd']) && is_string($input['pwd']) ? $input['pwd'] : '';
        $spoiler = isset($input['spoiler']) ? (bool) $input['spoiler'] : false;

        $data = [
            'name'            => $name,
            'email'           => $email,
            'subject'         => $subject,
            'content'         => $content,
            'password'        => $password,
            'spoiler'         => $spoiler,
            'ip_address'      => $this->getClientIp($request),
            // Media fields injected by API gateway after upload
            'media_url'       => $input['media_url'] ?? null,
            'thumb_url'       => $input['thumb_url'] ?? null,
            'media_filename'  => $input['media_filename'] ?? null,
            'media_size'      => $input['media_size'] ?? null,
            'media_dimensions'=> $input['media_dimensions'] ?? null,
            'media_hash'      => $input['media_hash'] ?? null,
        ];

        if (!$board->text_only && empty($data['content']) && empty($data['media_url'])) {
            return $this->response->json(['error' => 'A comment or image is required'])->withStatus(400);
        }

        try {
            $result = $this->boardService->createThread($board, $data);
            return $this->response->json($result)->withStatus(201);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    /** POST /api/v1/boards/{slug}/threads/{id}/posts – Reply to thread */
    public function reply(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $thread = Thread::find($id);
        if (!$thread) {
            return $this->response->json(['error' => 'Thread not found'])->withStatus(404);
        }

        $input = $request->all();
        $name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
        $email = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
        $subject = isset($input['sub']) && is_string($input['sub']) ? $input['sub'] : '';
        $content = isset($input['com']) && is_string($input['com']) ? $input['com'] : '';
        $password = isset($input['pwd']) && is_string($input['pwd']) ? $input['pwd'] : '';
        $spoiler = isset($input['spoiler']) ? (bool) $input['spoiler'] : false;

        $data = [
            'name'            => $name,
            'email'           => $email,
            'subject'         => $subject,
            'content'         => $content,
            'password'        => $password,
            'spoiler'         => $spoiler,
            'ip_address'      => $this->getClientIp($request),
            'media_url'       => $input['media_url'] ?? null,
            'thumb_url'       => $input['thumb_url'] ?? null,
            'media_filename'  => $input['media_filename'] ?? null,
            'media_size'      => $input['media_size'] ?? null,
            'media_dimensions'=> $input['media_dimensions'] ?? null,
            'media_hash'      => $input['media_hash'] ?? null,
        ];

        try {
            $result = $this->boardService->createPost($thread, $data);
            return $this->response->json($result)->withStatus(201);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()])->withStatus(422);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    /** GET /api/v1/boards/{slug}/threads/{id}/posts?after=0 – New posts */
    public function newPosts(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $afterInput = $request->query('after', '0');
        $after = is_numeric($afterInput) ? (int) $afterInput : 0;
        $posts = $this->boardService->getPostsAfter($id, $after);
        return $this->response->json(['posts' => $posts]);
    }

    /** POST /api/v1/posts/delete – Delete own posts */
    public function deletePost(RequestInterface $request): ResponseInterface
    {
        $idsInput = $request->input('ids', []);
        $ids = is_array($idsInput) ? $idsInput : [];
        $passwordInput = $request->input('password', '');
        $password = is_string($passwordInput) ? $passwordInput : '';
        $imageOnly = (bool) $request->input('image_only', false);

        if (empty($ids) || empty($password)) {
            return $this->response->json(['error' => 'Missing required fields'])->withStatus(400);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if (is_numeric($id) && $this->boardService->deletePost((int) $id, $password, $imageOnly)) {
                $deleted++;
            }
        }

        return $this->response->json(['deleted' => $deleted]);
    }

    private function getClientIp(RequestInterface $request): string
    {
        $ip = $request->getHeaderLine('X-Forwarded-For')
            ?: $request->getHeaderLine('X-Real-IP')
            ?: $request->server('remote_addr', '127.0.0.1');

        return is_string($ip) ? $ip : '127.0.0.1';
    }

    /** DELETE /api/v1/boards/{slug}/posts/{id} – Staff delete post */
    public function staffDeletePost(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $imageOnly = (bool) $request->query('file_only', false);
        
        // In production, authentication is handled by the Gateway.
        // We assume if this endpoint is reached, the user is authorized.
        
        $success = $this->boardService->staffDeletePost($id, $imageOnly);
        
        if ($success) {
            return $this->response->json(['success' => true]);
        }
        
        return $this->response->json(['error' => 'Failed to delete post'])->withStatus(500);
    }

    /** POST /api/v1/boards/{slug}/threads/{id}/options – Staff thread options */
    public function staffThreadOptions(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $option = $request->input('option');
        
        if (!is_string($option) || !in_array($option, ['sticky', 'lock', 'permasage'])) {
            return $this->response->json(['error' => 'Invalid option'])->withStatus(400);
        }
        
        $success = $this->boardService->toggleThreadOption($id, $option);
        
        if ($success) {
            return $this->response->json(['success' => true]);
        }
        
        return $this->response->json(['error' => 'Failed to toggle option'])->withStatus(500);
    }

    /** POST /api/v1/boards/{slug}/posts/{id}/spoiler – Staff toggle spoiler */
    public function staffSpoiler(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $success = $this->boardService->toggleSpoiler($id);
        
        if ($success) {
            return $this->response->json(['success' => true]);
        }
        
        return $this->response->json(['error' => 'Failed to toggle spoiler'])->withStatus(500);
    }

    /** GET /api/v1/boards/{slug}/threads/{id}/ips – Staff IP lookup */
    public function staffThreadIps(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        // Auth check assumed (gateway)
        $data = $this->boardService->getThreadIps($id);
        return $this->response->json($data);
    }
}
