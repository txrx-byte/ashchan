<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Controller\AbstractController;
use App\Service\ModerationService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * StaffReportController - Report queue interface for staff
 * 
 * Ported from OpenYotsuba/reports/ReportQueue.php
 * Provides the web interface for janitors/mods to manage reports
 */
#[Controller(prefix: '/staff/reports')]
class StaffReportController extends AbstractController
{
    public function __construct(
        private ModerationService $modService,
        private HttpResponse $response,
    ) {}

    /**
     * GET /staff/reports - Main report queue page
     * Ported from OpenYotsuba/reports/views/reportqueue.tpl.php
     */
    #[GetMapping(path: '')]
    public function index(RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        // Get query parameters
        $board = $request->query('board', '');
        $cleared = (bool) $request->query('cleared', false);
        $page = max(1, (int) $request->query('page', 1));
        
        // Get board list
        $boardlist = $this->getBoardList();
        
        // Determine access level
        $isMod = $staffInfo['is_mod'];
        $isManager = $staffInfo['is_manager'];
        $isAdmin = $staffInfo['is_admin'];
        
        return $this->response->view('staff/reports/index', [
            'boardlist' => $boardlist,
            'access' => [
                'board' => $staffInfo['boards'],
                'is_mod' => $isMod,
                'is_manager' => $isManager,
                'is_admin' => $isAdmin,
            ],
            'isMod' => $isMod,
            'isManager' => $isManager,
            'isAdmin' => $isAdmin,
            'currentBoard' => $board,
            'cleared_only' => $cleared,
            'username' => $staffInfo['username'],
        ]);
    }

    /**
     * GET /staff/reports/data - Fetch report data for AJAX
     */
    #[GetMapping(path: 'data')]
    public function data(RequestInterface $request): ResponseInterface
    {
        $board = $request->query('board');
        $cleared = (bool) $request->query('cleared', false);
        $page = max(1, (int) $request->query('page', 1));
        
        $data = $this->modService->getReportQueue(
            is_string($board) && $board !== '' ? $board : null,
            $cleared,
            $page,
            25
        );
        
        return $this->response->json($data);
    }

    /**
     * POST /staff/reports/{id}/clear - Clear a report
     */
    #[PostMapping(path: '{id:\d+}/clear')]
    public function clear(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        try {
            $this->modService->clearReport($id, $staffInfo['username']);
            return $this->response->json(['status' => 'success']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /staff/reports/{id}/delete - Delete a report
     */
    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        try {
            $this->modService->deleteReport($id);
            return $this->response->json(['status' => 'success']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /staff/reports/ban-requests - Ban requests page
     */
    #[GetMapping(path: 'ban-requests')]
    public function banRequests(RequestInterface $request): ResponseInterface
    {
        $board = $request->query('board');
        $data = $this->modService->getBanRequests(
            is_string($board) && $board !== '' ? $board : null
        );
        
        return $this->response->view('staff/reports/ban-requests', [
            'requests' => $data['requests'],
            'count' => $data['count'],
            'boardlist' => $this->getBoardList(),
            'isMod' => $this->getStaffInfo()['is_mod'],
        ]);
    }

    /**
     * POST /staff/reports/ban-requests/{id}/approve - Approve ban request
     */
    #[PostMapping(path: 'ban-requests/{id:\d+}/approve')]
    public function approveBanRequest(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_mod']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        try {
            $ban = $this->modService->approveBanRequest($id, $staffInfo['username']);
            return $this->response->json([
                'status' => 'success',
                'ban' => $ban->getSummary(),
            ]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /staff/reports/ban-requests/{id}/deny - Deny ban request
     */
    #[PostMapping(path: 'ban-requests/{id:\d+}/deny')]
    public function denyBanRequest(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_mod']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        try {
            $this->modService->denyBanRequest($id, $staffInfo['username']);
            return $this->response->json(['status' => 'success']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get staff info from context
     * @return array{username: string, level: string, boards: array, is_mod: bool, is_manager: bool, is_admin: bool}
     */
    private function getStaffInfo(): array
    {
        return \Hyperf\Context\Context::get('staff_info', [
            'username' => 'system',
            'level' => 'janitor',
            'boards' => [],
            'is_mod' => false,
            'is_manager' => false,
            'is_admin' => false,
        ]);
    }

    /**
     * Get board list
     * @return array<string>
     */
    private function getBoardList(): array
    {
        // In production, fetch from boards service
        return ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'];
    }
}
