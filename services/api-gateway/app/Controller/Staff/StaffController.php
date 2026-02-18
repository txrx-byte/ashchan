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
 * StaffController - Main staff portal controller
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

    /**
     * GET /staff - Redirect to dashboard or login
     */
    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $cookies = $this->request->getCookieParams();
        if (isset($cookies['staff_user'])) {
            return $this->response->redirect('/staff/dashboard');
        }
        return $this->response->redirect('/staff/login');
    }

    /**
     * GET /staff/login - Staff login page
     */
    #[GetMapping(path: 'login')]
    public function login(): ResponseInterface
    {
        $html = $this->viewService->render('staff/login', ['error' => null]);
        return $this->response->html($html);
    }

    /**
     * POST /staff/login - Process staff login
     */
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

        $response = $this->response->redirect('/staff/dashboard');
        
        // Use Hyperf Cookie objects
        $response = $response->withCookie(new Cookie('staff_user', $username, time() + 86400 * 30));
        $response = $response->withCookie(new Cookie('staff_token', hash('sha256', $username . time()), time() + 86400 * 30));
        $response = $response->withCookie(new Cookie('staff_level', 'janitor', time() + 86400 * 30));

        return $response;
    }

    /**
     * GET /staff/logout - Logout
     */
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
     * GET /staff/dashboard - Staff dashboard
     */
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

    /**
     * GET /staff/bans - Bans management page
     */
    #[GetMapping(path: 'bans')]
    public function bans(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        $html = $this->viewService->render('staff/bans/index', [
            'isMod' => $staffInfo['is_mod'],
            'isManager' => $staffInfo['is_manager'],
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
