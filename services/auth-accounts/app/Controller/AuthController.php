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
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        if (empty($username) || empty($password)) {
            return $this->response->json(['error' => 'Username and password required'], 400);
        }

        try {
            $result = $this->authService->login(
                $username,
                $password,
                $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', ''),
                $request->getHeaderLine('User-Agent')
            );

            if (!$result) {
                return $this->response->json(['error' => 'Invalid credentials'], 401);
            }

            return $this->response->json($result);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()], 403);
        }
    }

    /** POST /api/v1/auth/logout */
    #[RequestMapping(path: 'logout', methods: ['POST'])]
    public function logout(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token) {
            $this->authService->logout($token);
        }
        return $this->response->json(['status' => 'ok']);
    }

    /** GET /api/v1/auth/validate */
    #[RequestMapping(path: 'validate', methods: ['GET'])]
    public function validate(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if (!$token) {
            return $this->response->json(['error' => 'No token'], 401);
        }
        $user = $this->authService->validateToken($token);
        if (!$user) {
            return $this->response->json(['error' => 'Invalid or expired token'], 401);
        }
        return $this->response->json(['user' => $user]);
    }

    /** POST /api/v1/auth/register (admin only) */
    #[RequestMapping(path: 'register', methods: ['POST'])]
    public function register(RequestInterface $request): ResponseInterface
    {
        // Caller must be admin (checked at gateway level)
        $token = $this->extractToken($request);
        if ($token) {
            $caller = $this->authService->validateToken($token);
            if (!$caller || $caller['role'] !== 'admin') {
                return $this->response->json(['error' => 'Admin only'], 403);
            }
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $email    = (string) $request->input('email', '');
        $role     = (string) $request->input('role', 'user');

        if (empty($username) || empty($password)) {
            return $this->response->json(['error' => 'Username and password required'], 400);
        }

        try {
            $user = $this->authService->register($username, $password, $email, $role);
            return $this->response->json(['user' => ['id' => $user->id, 'username' => $user->username, 'role' => $user->role]], 201);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()], 409);
        }
    }

    /** POST /api/v1/auth/ban */
    #[RequestMapping(path: 'ban', methods: ['POST'])]
    public function ban(RequestInterface $request): ResponseInterface
    {
        $userId   = (int) $request->input('user_id', 0);
        $reason   = (string) $request->input('reason', '');
        $expires  = $request->input('expires_at');
        $ipHash   = (string) $request->input('ip_hash', '');
        $duration = (int) $request->input('duration', 86400);

        if ($userId) {
            $this->authService->banUser($userId, $reason, $expires);
        }
        if ($ipHash) {
            $this->authService->banIp($ipHash, $reason, $duration);
        }

        return $this->response->json(['status' => 'ok']);
    }

    /** POST /api/v1/auth/unban */
    #[RequestMapping(path: 'unban', methods: ['POST'])]
    public function unban(RequestInterface $request): ResponseInterface
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId) {
            $this->authService->unbanUser($userId);
        }
        return $this->response->json(['status' => 'ok']);
    }

    /** POST /api/v1/consent */
    #[RequestMapping(path: '/api/v1/consent', methods: ['POST'])]
    public function recordConsent(RequestInterface $request): ResponseInterface
    {
        $ipHash = hash('sha256', $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', ''));
        $consented = (bool) $request->input('consented', false);
        $version = (string) $request->input('policy_version', '1.0');

        $this->authService->recordConsent($ipHash, null, 'privacy_policy', $version, $consented);
        $this->authService->recordConsent($ipHash, null, 'age_verification', $version, $consented);

        return $this->response->json(['status' => 'ok']);
    }

    /** POST /api/v1/auth/data-request */
    #[RequestMapping(path: 'data-request', methods: ['POST'])]
    public function dataRequest(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if (!$token) {
            return $this->response->json(['error' => 'Authentication required'], 401);
        }
        $user = $this->authService->validateToken($token);
        if (!$user) {
            return $this->response->json(['error' => 'Invalid token'], 401);
        }

        $type = (string) $request->input('type', 'data_export');
        if ($type === 'data_deletion') {
            $req = $this->authService->requestDataDeletion($user['user_id']);
        } else {
            $req = $this->authService->requestDataExport($user['user_id']);
        }

        return $this->response->json(['request' => $req->toArray()], 201);
    }

    private function extractToken(RequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return $request->cookie('session_token');
    }
}
