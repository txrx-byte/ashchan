<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private AuthService $authService,
        private HttpResponse $response,
    ) {}

    /** POST /api/v1/auth/login */
    #[RequestMapping(path: 'login', methods: ['POST'])]
    public function login(RequestInterface $request): ResponseInterface
    {
        $username = $request->input('username', '');
        $password = $request->input('password', '');

        if (! is_string($username) || ! is_string($password) || empty($username) || empty($password)) {
            return $this->response->json(['error' => 'Username and password required']);
        }

        try {
            $remoteAddr = $request->server('remote_addr', '');
            $ip = $request->getHeaderLine('X-Forwarded-For') ?: (is_string($remoteAddr) ? $remoteAddr : '');

            $result = $this->authService->login(
                $username,
                $password,
                $ip,
                $request->getHeaderLine('User-Agent')
            );

            if (!$result) {
                return $this->response->json(['error' => 'Invalid credentials']);
            }

            return $this->response->json($result);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()]);
        }
    }

    /** POST /api/v1/auth/logout */
    #[RequestMapping(path: 'logout', methods: ['POST'])]
    public function logout(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token !== null) {
            $this->authService->logout($token);
        }
        return $this->response->json(['status' => 'ok']);
    }

    /** GET /api/v1/auth/validate */
    #[RequestMapping(path: 'validate', methods: ['GET'])]
    public function validate(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->response->json(['error' => 'No token']);
        }
        $user = $this->authService->validateToken($token);
        if (!$user) {
            return $this->response->json(['error' => 'Invalid or expired token']);
        }
        return $this->response->json(['user' => $user]);
    }

    /** POST /api/v1/auth/register (admin only) */
    #[RequestMapping(path: 'register', methods: ['POST'])]
    public function register(RequestInterface $request): ResponseInterface
    {
        // Caller must be admin (checked at gateway level)
        $token = $this->extractToken($request);
        if ($token !== null) {
            $caller = $this->authService->validateToken($token);
            if (!$caller || ($caller['role'] ?? '') !== 'admin') {
                return $this->response->json(['error' => 'Admin only']);
            }
        }

        $username = $request->input('username', '');
        $password = $request->input('password', '');
        $email    = $request->input('email', '');
        $role     = $request->input('role', 'user');

        if (! is_string($username) || ! is_string($password) || ! is_string($email) || ! is_string($role) || empty($username) || empty($password)) {
            return $this->response->json(['error' => 'Username and password required']);
        }

        try {
            $user = $this->authService->register($username, $password, $email, $role);
            return $this->response->json(['user' => ['id' => $user->id, 'username' => $user->username, 'role' => $user->role]]);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()]);
        }
    }

    /** POST /api/v1/auth/ban */
    #[RequestMapping(path: 'ban', methods: ['POST'])]
    public function ban(RequestInterface $request): ResponseInterface
    {
        $userId   = $request->input('user_id', 0);
        $reason   = $request->input('reason', '');
        $expires  = $request->input('expires_at');
        $ipHash   = $request->input('ip_hash', '');
        $duration = $request->input('duration', 86400);

        if (is_numeric($userId) && (int) $userId !== 0) {
            $this->authService->banUser((int) $userId, is_string($reason) ? $reason : '', is_string($expires) ? $expires : null);
        }
        if (is_string($ipHash) && $ipHash !== '') {
            $this->authService->banIp($ipHash, is_string($reason) ? $reason : '', is_numeric($duration) ? (int) $duration : 86400);
        }

        return $this->response->json(['status' => 'ok']);
    }

    /** POST /api/v1/auth/unban */
    #[RequestMapping(path: 'unban', methods: ['POST'])]
    public function unban(RequestInterface $request): ResponseInterface
    {
        $userId = $request->input('user_id');
        if (!is_numeric($userId)) {
            return $this->response->json(['error' => 'Invalid user ID']);
        }
        if ((int) $userId !== 0) {
            $this->authService->unbanUser((int) $userId);
        }
        return $this->response->json(['status' => 'ok']);
    }

    /** POST /api/v1/consent */
    #[RequestMapping(path: '/api/v1/consent', methods: ['POST'])]
    public function recordConsent(RequestInterface $request): ResponseInterface
    {
        $remoteAddr = $request->server('remote_addr', '');
        $ip = $request->getHeaderLine('X-Forwarded-For') ?: (is_string($remoteAddr) ? $remoteAddr : '');
        $ipHash = hash('sha256', $ip);
        $consented = (bool) $request->input('consented', false);
        $version = $request->input('policy_version', '1.0');

        $this->authService->recordConsent($ipHash, null, 'privacy_policy', is_string($version) ? $version : '1.0', $consented);
        $this->authService->recordConsent($ipHash, null, 'age_verification', is_string($version) ? $version : '1.0', $consented);

        return $this->response->json(['status' => 'ok']);
    }

    /** POST /api/v1/auth/data-request */
    #[RequestMapping(path: 'data-request', methods: ['POST'])]
    public function dataRequest(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->response->json(['error' => 'Authentication required']);
        }
        $user = $this->authService->validateToken($token);
        if (!$user) {
            return $this->response->json(['error' => 'Invalid token']);
        }

        $type = $request->input('type', 'data_export');
        $userId = $user['user_id'] ?? 0;
        if (!is_int($userId) || $userId === 0) {
            return $this->response->json(['error' => 'Invalid user ID']);
        }
        if (is_string($type) && $type === 'data_deletion') {
            $req = $this->authService->requestDataDeletion($userId);
        } else {
            $req = $this->authService->requestDataExport($userId);
        }

        return $this->response->json(['request' => $req->toArray()]);
    }

    private function extractToken(RequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        $cookies = $request->getCookieParams();
        $token = $cookies['session_token'] ?? null;
        return is_string($token) ? $token : null;
    }
}
