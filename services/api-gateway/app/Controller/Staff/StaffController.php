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
 * StaffController - Main staff portal controller
 * 
 * Provides login, dashboard, and navigation for the staff interface
 */
#[Controller(prefix: '/staff')]
class StaffController extends AbstractController
{
    public function __construct(
        private ModerationService $modService,
        private HttpResponse $response,
    ) {}

    /**
     * GET /staff - Redirect to dashboard or login
     */
    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        // Check if already logged in
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
        return $this->response->view('staff/login', [
            'error' => null,
        ]);
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
            return $this->response->view('staff/login', [
                'error' => 'Username and password required',
            ]);
        }
        
        // In production, verify against auth service / database
        // For now, accept any non-empty credentials for testing
        if ($username === '' || $password === '') {
            return $this->response->view('staff/login', [
                'error' => 'Invalid credentials',
            ]);
        }
        
        // Set staff cookies (mimics OpenYotsuba's 4chan_auser/4chan_apass)
        $response = $this->response->redirect('/staff/dashboard');
        
        // In production, these would be set with proper security flags
        $response = $response->withCookie('staff_user', $username, time() + 86400 * 30);
        $response = $response->withCookie('staff_token', hash('sha256', $username . time()), time() + 86400 * 30);
        $response = $response->withCookie('staff_level', 'janitor', time() + 86400 * 30);
        
        return $response;
    }

    /**
     * GET /staff/logout - Logout
     */
    #[GetMapping(path: 'logout')]
    public function logout(): ResponseInterface
    {
        $response = $this->response->redirect('/staff/login');
        
        // Clear staff cookies
        $response = $response->withCookie('staff_user', '', time() - 3600);
        $response = $response->withCookie('staff_token', '', time() - 3600);
        $response = $response->withCookie('staff_level', '', time() - 3600);
        
        return $response;
    }

    /**
     * GET /staff/dashboard - Staff dashboard
     */
    #[GetMapping(path: 'dashboard')]
    public function dashboard(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        // Get report counts
        $reportCounts = $this->modService->countReportsByBoard();
        $totalReports = array_sum($reportCounts);
        
        // Get ban request count
        $banRequests = $this->modService->getBanRequests();
        
        return $this->response->view('staff/dashboard', [
            'username' => $staffInfo['username'],
            'level' => $staffInfo['level'],
            'isMod' => $staffInfo['is_mod'],
            'isManager' => $staffInfo['is_manager'],
            'isAdmin' => $staffInfo['is_admin'],
            'totalReports' => $totalReports,
            'reportCounts' => $reportCounts,
            'banRequestCount' => $banRequests['count'],
        ]);
    }

    /**
     * GET /staff/bans - Bans management page
     */
    #[GetMapping(path: 'bans')]
    public function bans(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        return $this->response->view('staff/bans/index', [
            'isMod' => $staffInfo['is_mod'],
            'isManager' => $staffInfo['is_manager'],
        ]);
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
