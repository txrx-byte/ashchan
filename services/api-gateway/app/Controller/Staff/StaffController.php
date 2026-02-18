<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Service\ModerationService;
use App\Service\ViewService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\HttpMessage\Cookie\Cookie;
use Psr\Http\Message\ResponseInterface;

/**
 * StaffController - Main staff portal controller with comprehensive admin features
 */
#[Controller(prefix: '/staff')]
final class StaffController
{
    public function __construct(
        private ModerationService $modService,
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $cookies = $this->request->getCookieParams();
        if (isset($cookies['staff_user'])) {
            return $this->response->redirect('/staff/admin');
        }
        return $this->response->redirect('/staff/login');
    }

    #[GetMapping(path: 'login')]
    public function login(): ResponseInterface
    {
        $html = $this->viewService->render('staff/login', ['error' => null]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'login')]
    public function loginPost(RequestInterface $request): ResponseInterface
    {
        $username = $request->input('username');
        $password = $request->input('password');

        if (!is_string($username) || !is_string($password)) {
            $html = $this->viewService->render('staff/login', ['error' => 'Username and password required']);
            return $this->response->html($html);
        }

        if ($username === '' || $password === '') {
            $html = $this->viewService->render('staff/login', ['error' => 'Invalid credentials']);
            return $this->response->html($html);
        }

        $response = $this->response->redirect('/staff/admin');
        $response = $response->withCookie(new Cookie('staff_user', $username, time() + 86400 * 30));
        $response = $response->withCookie(new Cookie('staff_token', hash('sha256', $username . time()), time() + 86400 * 30));
        $response = $response->withCookie(new Cookie('staff_level', 'janitor', time() + 86400 * 30));

        return $response;
    }

    #[GetMapping(path: 'logout')]
    public function logout(): ResponseInterface
    {
        $response = $this->response->redirect('/staff/login');
        $response = $response->withCookie(new Cookie('staff_user', '', time() - 3600));
        $response = $response->withCookie(new Cookie('staff_token', '', time() - 3600));
        $response = $response->withCookie(new Cookie('staff_level', '', time() - 3600));
        return $response;
    }

    /**
     * Main Admin Dashboard - Centralized admin panel
     */
    #[GetMapping(path: 'admin')]
    public function admin(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        $reportCounts = $this->modService->countReportsByBoard();
        $totalReports = array_sum($reportCounts);
        $banRequests = $this->modService->getBanRequests();

        $html = $this->viewService->render('staff/admin', [
            'username' => $staffInfo['username'],
            'level' => $staffInfo['level'],
            'isMod' => $staffInfo['is_mod'],
            'isManager' => $staffInfo['is_manager'],
            'isAdmin' => $staffInfo['is_admin'],
            'totalReports' => $totalReports,
            'banRequestCount' => $banRequests['count'],
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'dashboard')]
    public function dashboard(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        $reportCounts = $this->modService->countReportsByBoard();
        $totalReports = array_sum($reportCounts);
        $banRequests = $this->modService->getBanRequests();

        $html = $this->viewService->render('staff/dashboard', [
            'username' => $staffInfo['username'],
            'level' => $staffInfo['level'],
            'isMod' => $staffInfo['is_mod'],
            'isManager' => $staffInfo['is_manager'],
            'isAdmin' => $staffInfo['is_admin'],
            'totalReports' => $totalReports,
            'reportCounts' => $reportCounts,
            'banRequestCount' => $banRequests['count'],
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'bans')]
    public function bans(RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        $search = $request->query('q', '');
        
        $html = $this->viewService->render('staff/bans/index', [
            'isMod' => $staffInfo['is_mod'],
            'isManager' => $staffInfo['is_manager'],
            'searchQuery' => $search,
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'reports')]
    public function reports(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        $html = $this->viewService->render('staff/reports/index', [
            'boardlist' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
            'access' => ['board' => []],
            'isMod' => $staffInfo['is_mod'],
            'isManager' => $staffInfo['is_manager'],
            'isAdmin' => $staffInfo['is_admin'],
            'currentBoard' => '',
            'cleared_only' => false,
            'username' => $staffInfo['username'],
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'reports/ban-requests')]
    public function banRequests(RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        $board = $request->query('board');
        $data = $this->modService->getBanRequests(is_string($board) && $board !== '' ? $board : null);

        $html = $this->viewService->render('staff/reports/ban-requests', [
            'requests' => $data['requests'],
            'count' => $data['count'],
            'boardlist' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
            'isMod' => $staffInfo['is_mod'],
        ]);
        return $this->response->html($html);
    }

    private function getStaffInfo(): array
    {
        $cookies = $this->request->getCookieParams();
        return [
            'username' => $cookies['staff_user'] ?? 'system',
            'level' => $cookies['staff_level'] ?? 'janitor',
            'is_mod' => in_array($cookies['staff_level'] ?? '', ['mod', 'manager', 'admin']),
            'is_manager' => in_array($cookies['staff_level'] ?? '', ['manager', 'admin']),
            'is_admin' => ($cookies['staff_level'] ?? '') === 'admin',
        ];
    }
}
