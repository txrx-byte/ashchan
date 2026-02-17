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
            return $this->response->json(['error' => 'Board not found'], 404);
        }
        $page = max(1, (int) $request->query('page', '1'));
        $data = $this->boardService->getThreadIndex($board, $page);
        return $this->response->json($data);
    }

    /** GET /api/v1/boards/{slug}/catalog */
    public function catalog(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'], 404);
        }
        $data = $this->boardService->getCatalog($board);
        return $this->response->json(['threads' => $data]);
    }

    /** GET /api/v1/boards/{slug}/archive */
    public function archive(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'], 404);
        }
        $data = $this->boardService->getArchive($board);
        return $this->response->json(['archived_threads' => $data]);
    }

    /** GET /api/v1/boards/{slug}/threads/{id} */
    public function show(string $slug, int $id): ResponseInterface
    {
        $data = $this->boardService->getThread($id);
        if (!$data) {
            return $this->response->json(['error' => 'Thread not found'], 404);
        }
        return $this->response->json($data);
    }

    /** POST /api/v1/boards/{slug}/threads – Create new thread */
    public function create(RequestInterface $request, string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'], 404);
        }

        $data = [
            'name'            => (string) $request->input('name', ''),
            'email'           => (string) $request->input('email', ''),
            'subject'         => (string) $request->input('sub', ''),
            'content'         => (string) $request->input('com', ''),
            'password'        => (string) $request->input('pwd', ''),
            'spoiler'         => (bool) $request->input('spoiler', false),
            'ip_hash'         => hash('sha256', $this->getClientIp($request)),
            // Media fields injected by API gateway after upload
            'media_url'       => $request->input('media_url'),
            'thumb_url'       => $request->input('thumb_url'),
            'media_filename'  => $request->input('media_filename'),
            'media_size'      => $request->input('media_size'),
            'media_dimensions'=> $request->input('media_dimensions'),
            'media_hash'      => $request->input('media_hash'),
        ];

        if (!$board->text_only && empty($data['content']) && empty($data['media_url'])) {
            return $this->response->json(['error' => 'A comment or image is required'], 400);
        }

        try {
            $result = $this->boardService->createThread($board, $data);
            return $this->response->json($result, 201);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/v1/boards/{slug}/threads/{id}/posts – Reply to thread */
    public function reply(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $thread = Thread::find($id);
        if (!$thread) {
            return $this->response->json(['error' => 'Thread not found'], 404);
        }

        $data = [
            'name'            => (string) $request->input('name', ''),
            'email'           => (string) $request->input('email', ''),
            'subject'         => (string) $request->input('sub', ''),
            'content'         => (string) $request->input('com', ''),
            'password'        => (string) $request->input('pwd', ''),
            'spoiler'         => (bool) $request->input('spoiler', false),
            'ip_hash'         => hash('sha256', $this->getClientIp($request)),
            'media_url'       => $request->input('media_url'),
            'thumb_url'       => $request->input('thumb_url'),
            'media_filename'  => $request->input('media_filename'),
            'media_size'      => $request->input('media_size'),
            'media_dimensions'=> $request->input('media_dimensions'),
            'media_hash'      => $request->input('media_hash'),
        ];

        try {
            $result = $this->boardService->createPost($thread, $data);
            return $this->response->json($result, 201);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /** GET /api/v1/boards/{slug}/threads/{id}/posts?after=0 – New posts */
    public function newPosts(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $after = max(0, (int) $request->query('after', '0'));
        $posts = $this->boardService->getPostsAfter($id, $after);
        return $this->response->json(['posts' => $posts]);
    }

    /** POST /api/v1/posts/delete – Delete own posts */
    public function deletePost(RequestInterface $request): ResponseInterface
    {
        $ids = (array) $request->input('ids', []);
        $password = (string) $request->input('password', '');
        $imageOnly = (bool) $request->input('image_only', false);

        if (empty($ids) || empty($password)) {
            return $this->response->json(['error' => 'Missing required fields'], 400);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->boardService->deletePost((int) $id, $password, $imageOnly)) {
                $deleted++;
            }
        }

        return $this->response->json(['deleted' => $deleted]);
    }

    private function getClientIp(RequestInterface $request): string
    {
        return $request->getHeaderLine('X-Forwarded-For')
            ?: $request->getHeaderLine('X-Real-IP')
            ?: $request->server('remote_addr', '127.0.0.1');
    }
}
