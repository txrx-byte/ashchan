<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Service\AuthenticationService;
use App\Service\ModerationService;
use App\Service\ViewService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * StaffController - Production staff portal with real authentication
 */
#[Controller(prefix: '/staff')]
final class StaffController
{
    public function __construct(
        private AuthenticationService $authService,
        private ModerationService $modService,
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $user = $this->getUser();
        if ($user) {
            return $this->response->redirect('/staff/admin');
        }
        return $this->response->redirect('/staff/login');
    }

    #[GetMapping(path: 'login')]
    public function login(): ResponseInterface
    {
        // If already logged in, redirect to admin
        if ($this->getUser()) {
            return $this->response->redirect('/staff/admin');
        }
        
        $error = $this->request->query('error', '');
        $html = $this->viewService->render('staff/login', [
            'error' => $error,
            'csrf_token' => '',
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'login')]
    public function loginPost(): ResponseInterface
    {
        // If already logged in, redirect
        if ($this->getUser()) {
            return $this->response->redirect('/staff/admin');
        }
        
        $body = $this->request->parsedBody();
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';
        
        if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
            return $this->response->redirect('/staff/login?error=' . urlencode('Username and password required'));
        }
        
        $serverParams = $this->request->getServerParams();
        $ipAddress = $serverParams['remote_addr'] ?? '0.0.0.0';
        $userAgent = $this->request->getHeaderLine('User-Agent');
        
        // Authenticate
        $result = $this->authService->authenticate($username, $password, $ipAddress, $userAgent);
        
        if (!$result['success']) {
            $errorMsg = $result['error'] ?? 'Login failed';
            if (isset($result['lockout_remaining'])) {
                $errorMsg .= ' (' . ceil($result['lockout_remaining'] / 60) . ' minutes remaining)';
            }
            return $this->response->redirect('/staff/login?error=' . urlencode($errorMsg));
        }
        
        // Set session cookie (secure, httponly, samesite)
        $response = $this->response->redirect('/staff/admin');
        $response = $response->withCookie(
            \Hyperf\HttpMessage\Cookie\Cookie::create(
                'staff_session',
                $result['session_token'],
                time() + (8 * 3600), // 8 hours
                '/',
                null,
                true, // secure - only over HTTPS in production
                true, // httponly
                false, // not raw
                'Strict' // samesite
            )
        );
        
        return $response;
    }

    #[GetMapping(path: 'logout')]
    public function logout(): ResponseInterface
    {
        $user = $this->getUser();
        $cookies = $this->request->getCookieParams();
        $sessionToken = $cookies['staff_session'] ?? null;
        
        if ($user && $sessionToken) {
            $tokenHash = hash('sha256', $sessionToken);
            $this->authService->logout($tokenHash, $user['id']);
        }
        
        // Clear session cookie
        $response = $this->response->redirect('/staff/login');
        $response = $response->withCookie(
            \Hyperf\HttpMessage\Cookie\Cookie::create(
                'staff_session',
                '',
                time() - 3600,
                '/',
                null,
                true,
                true
            )
        );
        
        return $response;
    }

    #[GetMapping(path: 'admin')]
    public function admin(): ResponseInterface
    {
        $user = $this->getUser();
        $reportCounts = $this->modService->countReportsByBoard();
        $totalReports = array_sum($reportCounts);
        $banRequests = $this->modService->getBanRequests();

        $html = $this->viewService->render('staff/admin', [
            'username' => $user['username'],
            'level' => $user['access_level'],
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'isManager' => in_array($user['access_level'], ['manager', 'admin']),
            'isAdmin' => $user['access_level'] === 'admin',
            'totalReports' => $totalReports,
            'banRequestCount' => $banRequests['count'],
            'csrf_token' => $this->authService->generateCsrfToken($user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'dashboard')]
    public function dashboard(): ResponseInterface
    {
        $user = $this->getUser();
        $reportCounts = $this->modService->countReportsByBoard();
        $totalReports = array_sum($reportCounts);
        $banRequests = $this->modService->getBanRequests();

        $html = $this->viewService->render('staff/dashboard', [
            'username' => $user['username'],
            'level' => $user['access_level'],
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'isManager' => in_array($user['access_level'], ['manager', 'admin']),
            'isAdmin' => $user['access_level'] === 'admin',
            'totalReports' => $totalReports,
            'reportCounts' => $reportCounts,
            'banRequestCount' => $banRequests['count'],
            'csrf_token' => $this->authService->generateCsrfToken($user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'bans')]
    public function bans(): ResponseInterface
    {
        $user = $this->getUser();
        $search = $this->request->query('q', '');
        
        $html = $this->viewService->render('staff/bans/index', [
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'isManager' => in_array($user['access_level'], ['manager', 'admin']),
            'searchQuery' => $search,
            'csrf_token' => $this->authService->generateCsrfToken($user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'reports')]
    public function reports(): ResponseInterface
    {
        $user = $this->getUser();
        
        $html = $this->viewService->render('staff/reports/index', [
            'boardlist' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
            'access' => ['board' => $user['board_access']],
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'isManager' => in_array($user['access_level'], ['manager', 'admin']),
            'isAdmin' => $user['access_level'] === 'admin',
            'currentBoard' => '',
            'cleared_only' => false,
            'username' => $user['username'],
            'csrf_token' => $this->authService->generateCsrfToken($user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'reports/ban-requests')]
    public function banRequests(): ResponseInterface
    {
        $user = $this->getUser();
        $board = $this->request->query('board');
        $data = $this->modService->getBanRequests(is_string($board) && $board !== '' ? $board : null);

        $html = $this->viewService->render('staff/reports/ban-requests', [
            'requests' => $data['requests'],
            'count' => $data['count'],
            'boardlist' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'csrf_token' => $this->authService->generateCsrfToken($user['id']),
        ]);
        return $this->response->html($html);
    }

    /**
     * Get current user from context
     */
    private function getUser(): ?array
    {
        return \Hyperf\Context\Context::get('staff_user');
    }
}
