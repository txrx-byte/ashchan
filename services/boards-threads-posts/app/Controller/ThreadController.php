<?php
declare(strict_types=1);

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
            'ip_hash'         => hash('sha256', $this->getClientIp($request)),
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
            'ip_hash'         => hash('sha256', $this->getClientIp($request)),
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
}
