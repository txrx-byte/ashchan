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
        $params = $request->getQueryParams();
        $query = $params['q'] ?? '';
        $board = $params['board'] ?? null;
        $pageVal = $params['page'] ?? 1;
        $page  = max(1, is_numeric($pageVal) ? (int) $pageVal : 1);

        if (!is_string($query) || mb_strlen($query) < 2) {
            return $this->response->json(['error' => 'Query must be at least 2 characters']);
        }
        if (!is_null($board) && !is_string($board)) {
            return $this->response->json(['error' => 'Invalid board']);
        }

        if (is_string($board) && $board !== '') {
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
        $board    = $request->input('board');
        $threadId = $request->input('thread_id');
        $postId   = $request->input('post_id');
        $content  = $request->input('content');
        $subject  = $request->input('subject');

        if (!is_string($board) || !is_int($threadId) || !is_int($postId) || !is_string($content) || (!is_null($subject) && !is_string($subject))) {
            return $this->response->json(['error' => 'Invalid input']);
        }
        if (!$board || !$postId) {
            return $this->response->json(['error' => 'board and post_id required']);
        }

        $this->searchService->indexPost($board, $threadId, $postId, $content, $subject);
        return $this->response->json(['status' => 'indexed']);
    }

    /** DELETE /api/v1/search/index – Remove from index (internal) */
    #[RequestMapping(path: 'index', methods: ['DELETE'])]
    public function remove(RequestInterface $request): ResponseInterface
    {
        $board  = $request->input('board');
        $postId = $request->input('post_id');

        if (!is_string($board) || !is_int($postId) || !$board || !$postId) {
            return $this->response->json(['error' => 'board and post_id required']);
        }

        $this->searchService->removePost($board, $postId);
        return $this->response->json(['status' => 'removed']);
    }
}
