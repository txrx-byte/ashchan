<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\BoardService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

final class BoardController
{
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /** GET /api/v1/boards */
    public function list(): ResponseInterface
    {
        $boards = $this->boardService->listBoards();
        return $this->response->json(['boards' => $boards]);
    }

    /** GET /api/v1/boards/{slug} */
    public function show(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        return $this->response->json(['board' => $board->toArray()]);
    }

    /** GET /api/v1/blotter */
    public function blotter(): ResponseInterface
    {
        $blotter = $this->boardService->getBlotter();
        return $this->response->json(['blotter' => $blotter]);
    }
}
