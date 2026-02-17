<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\SearchService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/v1/search')]
final class SearchController
{
    public function __construct(
        private SearchService $searchService,
        private HttpResponse $response,
    ) {}

    /** GET /api/v1/search?q=term&board=g&page=1 */
    #[RequestMapping(path: '', methods: ['GET'])]
    public function search(RequestInterface $request): ResponseInterface
    {
        $query = (string) $request->query('q', '');
        $board = $request->query('board');
        $page  = max(1, (int) $request->query('page', '1'));

        if (mb_strlen($query) < 2) {
            return $this->response->json(['error' => 'Query must be at least 2 characters'], 400);
        }

        if ($board) {
            $data = $this->searchService->search($board, $query, $page);
        } else {
            $data = $this->searchService->searchAll($query, $page);
        }

        return $this->response->json($data);
    }

    /** POST /api/v1/search/index – Index a post (internal) */
    #[RequestMapping(path: 'index', methods: ['POST'])]
    public function index(RequestInterface $request): ResponseInterface
    {
        $board    = (string) $request->input('board', '');
        $threadId = (int) $request->input('thread_id', 0);
        $postId   = (int) $request->input('post_id', 0);
        $content  = (string) $request->input('content', '');
        $subject  = $request->input('subject');

        if (!$board || !$postId) {
            return $this->response->json(['error' => 'board and post_id required'], 400);
        }

        $this->searchService->indexPost($board, $threadId, $postId, $content, $subject);
        return $this->response->json(['status' => 'indexed']);
    }

    /** DELETE /api/v1/search/index – Remove from index (internal) */
    #[RequestMapping(path: 'index', methods: ['DELETE'])]
    public function remove(RequestInterface $request): ResponseInterface
    {
        $board  = (string) $request->input('board', '');
        $postId = (int) $request->input('post_id', 0);

        if (!$board || !$postId) {
            return $this->response->json(['error' => 'board and post_id required'], 400);
        }

        $this->searchService->removePost($board, $postId);
        return $this->response->json(['status' => 'removed']);
    }
}
