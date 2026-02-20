<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace App\Controller\Staff;

use App\Service\AuthenticationService;
use App\Service\ModerationService;
use App\Service\ViewService;
use Hyperf\HttpMessage\Cookie\Cookie;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

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
        if ($this->getUser()) {
            return $this->response->redirect('/staff/admin');
        }
        $error = $this->request->query('error', '');
        $html = $this->viewService->render('staff/login', ['error' => $error, 'csrf_token' => '']);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'login')]
    public function loginPost(): ResponseInterface
    {
        if ($this->getUser()) {
            return $this->response->redirect('/staff/admin');
        }
        
        /** @var array<string, mixed> $body */
        
        $body = (array) $this->request->getParsedBody();
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';
        
        if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
            return $this->response->redirect('/staff/login?error=' . urlencode('Username and password required'));
        }
        
        $serverParams = $this->request->getServerParams();
        $ipAddress = (string) ($serverParams['remote_addr'] ?? '0.0.0.0');
        $userAgent = $this->request->getHeaderLine('User-Agent');
        
        $result = $this->authService->authenticate($username, $password, $ipAddress, $userAgent);
        
        if (!$result['success']) {
            $errorMsg = $result['error'] ?? 'Login failed';
            if (isset($result['lockout_remaining'])) {
                $errorMsg .= ' (' . ceil($result['lockout_remaining'] / 60) . ' minutes)';
            }
            return $this->response->redirect('/staff/login?error=' . urlencode($errorMsg));
        }
        
        $token = isset($result['session_token']) ? $result['session_token'] : '';
        /** @var \Hyperf\HttpServer\Response $response */
        $response = $this->response->redirect('/staff/admin');
        $cookie = new Cookie('staff_session', $token, time() + (8 * 3600), '/', '', true, true, false, 'Strict');
        /** @var ResponseInterface $cookieResponse */
        $cookieResponse = $response->withCookie($cookie);

        return $cookieResponse;
    }

    #[PostMapping(path: 'logout')]
    public function logout(): ResponseInterface
    {
        $user = $this->getUser();
        $cookies = $this->request->getCookieParams();
        $sessionToken = $cookies['staff_session'] ?? null;

        if ($user && $sessionToken) {
            $tokenHash = hash('sha256', (string) $sessionToken);
            $this->authService->logout($tokenHash, (int) $user['id']);
        }

        /** @var \Hyperf\HttpServer\Response $response */
        $response = $this->response->redirect('/staff/login');
        $cookie = new Cookie('staff_session', '', time() - 3600, '/', '', true, true);
        /** @var ResponseInterface $cookieResponse */
        $cookieResponse = $response->withCookie($cookie);
        
        return $cookieResponse;
    }

    #[GetMapping(path: 'admin')]
    public function admin(): ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
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
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'dashboard')]
    public function dashboard(): ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
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
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'bans')]
    public function bans(): ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $search = $this->request->query('q', '');
        
        $html = $this->viewService->render('staff/bans/index', [
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'isManager' => in_array($user['access_level'], ['manager', 'admin']),
            'searchQuery' => $search,
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'reports')]
    public function reports(): ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }

        $html = $this->viewService->render('staff/reports/index', [
            'boardlist' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
            'access' => ['board' => $user['board_access']],
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'isManager' => in_array($user['access_level'], ['manager', 'admin']),
            'isAdmin' => $user['access_level'] === 'admin',
            'currentBoard' => '',
            'cleared_only' => false,
            'username' => $user['username'],
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'reports/ban-requests')]
    public function banRequests(): ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $board = $this->request->query('board');
        $data = $this->modService->getBanRequests(is_string($board) && $board !== '' ? $board : null);

        $html = $this->viewService->render('staff/reports/ban-requests', [
            'requests' => $data['requests'],
            'count' => $data['count'],
            'boardlist' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
            'isMod' => in_array($user['access_level'], ['mod', 'manager', 'admin']),
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'bans/unban')]
    public function unban(): ResponseInterface
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->response->json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $ids = $body['ids'] ?? '';
        
        if (empty($ids)) {
            return $this->response->json(['status' => 'error', 'message' => 'No IDs provided']);
        }
        
        $idList = explode(',', (string) $ids);
        $username = (string) ($user['username'] ?? '');
        
        foreach ($idList as $id) {
            if (is_numeric($id)) {
                $this->modService->unbanUser((int)$id, $username);
            }
        }
        
        return $this->response->json(['status' => 'success']);
    }

    #[GetMapping(path: 'dashboard/stats')]
    public function dashboardStats(): ResponseInterface
    {
        $mode = $this->request->query('mode');
        $data = [];

        try {
            if ($mode === 'clr') {
                // Cleared reports by staff member in last 24h
                $rows = \Hyperf\DbConnection\Db::table('reports')
                    ->select(\Hyperf\DbConnection\Db::raw('cleared_by, COUNT(*) as cnt'))
                    ->where('cleared', 1)
                    ->where('updated_at', '>', now()->subDay())
                    ->groupBy('cleared_by')
                    ->orderByDesc('cnt')
                    ->limit(20)
                    ->get();
                foreach ($rows as $row) {
                    $data[(string) $row->cleared_by] = (int) $row->cnt;
                }
            } elseif ($mode === 'del') {
                // Deletions by staff member in last 24h
                $rows = \Hyperf\DbConnection\Db::table('admin_audit_log')
                    ->select(\Hyperf\DbConnection\Db::raw('username, COUNT(*) as cnt'))
                    ->where('action_type', 'delete')
                    ->where('created_at', '>', now()->subDay())
                    ->groupBy('username')
                    ->orderByDesc('cnt')
                    ->limit(20)
                    ->get();
                foreach ($rows as $row) {
                    $data[(string) $row->username] = (int) $row->cnt;
                }
            } elseif ($mode === 'fence_skip') {
                // Ban evasion attempts in last 24h
                $rows = \Hyperf\DbConnection\Db::table('admin_audit_log')
                    ->select(\Hyperf\DbConnection\Db::raw('board, COUNT(*) as cnt'))
                    ->where('action_type', 'ban_evasion')
                    ->where('created_at', '>', now()->subDay())
                    ->groupBy('board')
                    ->orderByDesc('cnt')
                    ->limit(20)
                    ->get();
                foreach ($rows as $row) {
                    $data[(string) $row->board] = (int) $row->cnt;
                }
            }
        } catch (\Throwable $e) {
            // Tables may not exist yet — return empty data
        }

        return $this->response->json(['status' => 'success', 'data' => $data]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getUser(): ?array
    {
        /** @var array<string, mixed>|null */
        return \Hyperf\Context\Context::get('staff_user');
    }
}
