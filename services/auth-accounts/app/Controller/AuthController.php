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


namespace App\Controller;

use App\Service\AuthService;
use App\Service\PiiEncryptionService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Authentication controller handling login, registration, bans, consent, and data rights.
 *
 * All endpoints enforce proper HTTP status codes, input validation, and
 * constant-time error messages to prevent user enumeration.
 *
 * Security measures:
 *  - Login brute-force protection via per-IP rate limiting in Redis
 *  - Constant-time credential error responses (no user enumeration)
 *  - Role-based access control for admin/mod operations
 *  - HMAC-based IP hashing to prevent rainbow table attacks
 *  - Input length and format validation on all user-supplied data
 */
#[Controller(prefix: '/api/v1/auth')]
final class AuthController
{
    /** Maximum allowed username length to prevent abuse. */
    private const MAX_USERNAME_LENGTH = 64;

    /** Maximum allowed password length to prevent hash-DoS attacks. */
    private const MAX_PASSWORD_LENGTH = 256;

    /** Maximum allowed email length per RFC 5321. */
    private const MAX_EMAIL_LENGTH = 254;

    /** Maximum login attempts per IP within the rate-limit window. */
    private const LOGIN_RATE_LIMIT = 10;

    /** Rate-limit sliding window in seconds. */
    private const LOGIN_RATE_WINDOW = 300;

    /** Allowed staff roles for registration. */
    private const ALLOWED_ROLES = ['admin', 'manager', 'mod', 'janitor', 'user'];

    /** Roles permitted to perform ban/unban operations. */
    private const BAN_ROLES = ['admin', 'manager', 'mod'];

    /** Maximum ban duration: 1 year in seconds. */
    private const MAX_BAN_DURATION = 31536000;

    /** Minimum ban duration: 60 seconds. */
    private const MIN_BAN_DURATION = 60;

    public function __construct(
        private AuthService $authService,
        private PiiEncryptionService $piiEncryption,
        private HttpResponse $response,
    ) {}

    /**
     * Authenticate a staff user and issue a session token.
     *
     * Enforces per-IP rate limiting to mitigate brute-force attacks.
     * Returns a generic error message for both invalid username and password
     * to prevent user enumeration.
     *
     * POST /api/v1/auth/login
     * Body: { username: string, password: string }
     * Success 200: { token, expires_in, user: { id, username, role } }
     * Error 400: Missing/invalid input
     * Error 401: Invalid credentials
     * Error 429: Rate limited
     */
    #[RequestMapping(path: 'login', methods: ['POST'])]
    public function login(RequestInterface $request): ResponseInterface
    {
        $username = $request->input('username', '');
        $password = $request->input('password', '');

        if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
            return $this->response->json(['error' => 'Username and password required'])->withStatus(400);
        }

        // Enforce input length limits to prevent abuse
        if (strlen($username) > self::MAX_USERNAME_LENGTH) {
            return $this->response->json(['error' => 'Username too long'])->withStatus(400);
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return $this->response->json(['error' => 'Password too long'])->withStatus(400);
        }

        $remoteAddr = $request->server('remote_addr', '');
        $ip = is_string($remoteAddr) ? $remoteAddr : '';

        // Per-IP login rate limiting (sliding window)
        if ($this->isLoginRateLimited($ip)) {
            return $this->response->json(['error' => 'Too many login attempts, try again later'])->withStatus(429);
        }

        try {
            $result = $this->authService->login(
                $username,
                $password,
                $ip,
                $request->getHeaderLine('User-Agent')
            );

            if ($result === null) {
                // Generic message prevents user enumeration
                return $this->response->json(['error' => 'Invalid credentials'])->withStatus(401);
            }

            return $this->response->json($result);
        } catch (\RuntimeException $e) {
            // Don't leak ban details — return generic message
            return $this->response->json(['error' => 'Invalid credentials'])->withStatus(401);
        }
    }

    /**
     * Invalidate the current session token.
     *
     * POST /api/v1/auth/logout
     * Header: Authorization: Bearer <token>
     * Success 200: { status: 'ok' }
     */
    #[RequestMapping(path: 'logout', methods: ['POST'])]
    public function logout(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token !== null) {
            $this->authService->logout($token);
        }
        return $this->response->json(['status' => 'ok']);
    }

    /**
     * Validate a session token and return the associated user info.
     *
     * GET /api/v1/auth/validate
     * Header: Authorization: Bearer <token>
     * Success 200: { user: { user_id, username, role } }
     * Error 401: Missing or invalid token
     */
    #[RequestMapping(path: 'validate', methods: ['GET'])]
    public function validate(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->response->json(['error' => 'No token'])->withStatus(401);
        }

        $user = $this->authService->validateToken($token);
        if ($user === null) {
            return $this->response->json(['error' => 'Invalid or expired token'])->withStatus(401);
        }

        return $this->response->json(['user' => $user]);
    }

    /**
     * Register a new staff user (admin-only).
     *
     * POST /api/v1/auth/register
     * Header: Authorization: Bearer <admin-token>
     * Body: { username, password, email?, role? }
     * Success 201: { user: { id, username, role } }
     * Error 400: Invalid input
     * Error 401: Not authenticated
     * Error 403: Not admin
     * Error 409: Username taken
     */
    #[RequestMapping(path: 'register', methods: ['POST'])]
    public function register(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->response->json(['error' => 'Authentication required'])->withStatus(401);
        }

        $caller = $this->authService->validateToken($token);
        if ($caller === null || ($caller['role'] ?? '') !== 'admin') {
            return $this->response->json(['error' => 'Admin only'])->withStatus(403);
        }

        $username = $request->input('username', '');
        $password = $request->input('password', '');
        $email    = $request->input('email', '');
        $role     = $request->input('role', 'user');

        if (!is_string($username) || !is_string($password) || !is_string($email) || !is_string($role)) {
            return $this->response->json(['error' => 'Invalid input types'])->withStatus(400);
        }

        if ($username === '' || $password === '') {
            return $this->response->json(['error' => 'Username and password required'])->withStatus(400);
        }

        // Input length and format validation
        if (strlen($username) > self::MAX_USERNAME_LENGTH) {
            return $this->response->json(['error' => 'Username too long'])->withStatus(400);
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            return $this->response->json(['error' => 'Username may only contain letters, numbers, hyphens, and underscores'])->withStatus(400);
        }
        if (strlen($password) < 12) {
            return $this->response->json(['error' => 'Password must be at least 12 characters'])->withStatus(400);
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return $this->response->json(['error' => 'Password too long'])->withStatus(400);
        }
        if ($email !== '' && (strlen($email) > self::MAX_EMAIL_LENGTH || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return $this->response->json(['error' => 'Invalid email address'])->withStatus(400);
        }
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            return $this->response->json(['error' => 'Invalid role'])->withStatus(400);
        }

        try {
            $user = $this->authService->register($username, $password, $email, $role);
            return $this->response->json([
                'user' => ['id' => $user->id, 'username' => $user->username, 'role' => $user->role],
            ])->withStatus(201);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()])->withStatus(409);
        }
    }

    /**
     * Ban a user by ID and/or an IP by hash.
     *
     * POST /api/v1/auth/ban
     * Header: Authorization: Bearer <staff-token>
     * Body: { user_id?: int, ip_hash?: string, reason?: string, expires_at?: string, duration?: int }
     * Success 200: { status: 'ok' }
     * Error 400: No target specified or invalid parameters
     * Error 401/403: Auth failures
     */
    #[RequestMapping(path: 'ban', methods: ['POST'])]
    public function ban(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->response->json(['error' => 'Authentication required'])->withStatus(401);
        }

        $caller = $this->authService->validateToken($token);
        if ($caller === null || !in_array($caller['role'] ?? '', self::BAN_ROLES, true)) {
            return $this->response->json(['error' => 'Insufficient privileges'])->withStatus(403);
        }

        $userId   = $request->input('user_id', 0);
        $reason   = $request->input('reason', '');
        $expires  = $request->input('expires_at');
        $ipHash   = $request->input('ip_hash', '');
        $duration = $request->input('duration', 86400);

        $hasUserTarget = is_numeric($userId) && (int) $userId > 0;
        $hasIpTarget   = is_string($ipHash) && $ipHash !== '';

        // Require at least one ban target
        if (!$hasUserTarget && !$hasIpTarget) {
            return $this->response->json(['error' => 'Must specify user_id or ip_hash'])->withStatus(400);
        }

        // Validate duration bounds
        $durationInt = is_numeric($duration) ? (int) $duration : 86400;
        $durationInt = max(self::MIN_BAN_DURATION, min(self::MAX_BAN_DURATION, $durationInt));

        $reasonStr = is_string($reason) ? mb_substr($reason, 0, 500) : '';

        if ($hasUserTarget) {
            $expiresStr = is_string($expires) ? $expires : null;
            $this->authService->banUser((int) $userId, $reasonStr, $expiresStr);
        }
        if ($hasIpTarget) {
            $this->authService->banIp($ipHash, $reasonStr, $durationInt);
        }

        return $this->response->json(['status' => 'ok']);
    }

    /**
     * Remove a ban from a user.
     *
     * POST /api/v1/auth/unban
     * Header: Authorization: Bearer <staff-token>
     * Body: { user_id: int }
     * Success 200: { status: 'ok' }
     * Error 400/401/403
     */
    #[RequestMapping(path: 'unban', methods: ['POST'])]
    public function unban(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->response->json(['error' => 'Authentication required'])->withStatus(401);
        }

        $caller = $this->authService->validateToken($token);
        if ($caller === null || !in_array($caller['role'] ?? '', self::BAN_ROLES, true)) {
            return $this->response->json(['error' => 'Insufficient privileges'])->withStatus(403);
        }

        $userId = $request->input('user_id');
        if (!is_numeric($userId) || (int) $userId <= 0) {
            return $this->response->json(['error' => 'Invalid user ID'])->withStatus(400);
        }

        $this->authService->unbanUser((int) $userId);
        return $this->response->json(['status' => 'ok']);
    }

    /**
     * Record user consent for privacy/age policies (GDPR/COPPA/CCPA).
     *
     * Uses HMAC-SHA256 for IP hashing (with a secret key) to prevent rainbow-table
     * reversal of the IPv4 address space.
     *
     * POST /api/v1/consent
     * Body: { consented: bool, policy_version?: string }
     * Success 200: { status: 'ok' }
     */
    #[RequestMapping(path: '/api/v1/consent', methods: ['POST'])]
    public function recordConsent(RequestInterface $request): ResponseInterface
    {
        $remoteAddr = $request->server('remote_addr', '');
        $ip = is_string($remoteAddr) ? $remoteAddr : '';

        // Encrypt IP for admin decryption; HMAC-hash for deterministic lookups
        $encryptedIp = $this->piiEncryption->encrypt($ip);
        $ipHash = $this->authService->hashIp($ip);

        $consented = (bool) $request->input('consented', false);
        $version = $request->input('policy_version', '1.0');
        $versionStr = is_string($version) ? mb_substr($version, 0, 20) : '1.0';

        $this->authService->recordConsent($ipHash, $encryptedIp, null, 'privacy_policy', $versionStr, $consented);
        $this->authService->recordConsent($ipHash, $encryptedIp, null, 'age_verification', $versionStr, $consented);

        return $this->response->json(['status' => 'ok']);
    }

    /**
     * Request a data export or deletion (GDPR/CCPA data rights).
     *
     * POST /api/v1/auth/data-request
     * Header: Authorization: Bearer <token>
     * Body: { type?: 'data_export' | 'data_deletion' }
     * Success 200: { request: { ... } }
     * Error 401: Not authenticated
     */
    #[RequestMapping(path: 'data-request', methods: ['POST'])]
    public function dataRequest(RequestInterface $request): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->response->json(['error' => 'Authentication required'])->withStatus(401);
        }

        $user = $this->authService->validateToken($token);
        if ($user === null) {
            return $this->response->json(['error' => 'Invalid token'])->withStatus(401);
        }

        $type = $request->input('type', 'data_export');
        $userId = $user['user_id'] ?? 0;
        if (!is_int($userId) || $userId <= 0) {
            return $this->response->json(['error' => 'Invalid user'])->withStatus(400);
        }

        if (is_string($type) && $type === 'data_deletion') {
            $req = $this->authService->requestDataDeletion($userId);
        } else {
            $req = $this->authService->requestDataExport($userId);
        }

        return $this->response->json(['request' => $req->toArray()]);
    }

    /**
     * Extract the Bearer token from the Authorization header or session_token cookie.
     *
     * @return string|null The raw token, or null if not found
     */
    private function extractToken(RequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            $token = trim(substr($auth, 7));
            return $token !== '' ? $token : null;
        }

        $cookies = $request->getCookieParams();
        $token = $cookies['session_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Check if an IP has exceeded the login rate limit.
     *
     * Uses a Redis sorted-set sliding window: each member is a microtime entry,
     * and we count recent entries within the window.
     *
     * @param string $ip The client IP address
     * @return bool True if rate-limited
     */
    private function isLoginRateLimited(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        try {
            $redis = $this->authService->getRedis();
            $key = 'login_attempts:' . hash('sha256', $ip);
            $now = microtime(true);

            // Atomic Lua script: clean expired entries, check count, add new entry
            // This prevents race conditions where concurrent requests could bypass the limit
            $luaScript = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window_start = tonumber(ARGV[2])
local max_reqs = tonumber(ARGV[3])
local member = ARGV[4]
local window = tonumber(ARGV[5])
redis.call('ZREMRANGEBYSCORE', key, '-inf', window_start)
local count = redis.call('ZCARD', key)
if count >= max_reqs then
    return 1
end
redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, window)
return 0
LUA;

            $windowStart = $now - self::LOGIN_RATE_WINDOW;
            $member = (string) $now . ':' . bin2hex(random_bytes(4));

            /** @var int $result */
            $result = $redis->eval(
                $luaScript,
                [
                    $key,
                    (string) $now,
                    (string) $windowStart,
                    (string) self::LOGIN_RATE_LIMIT,
                    $member,
                    (string) self::LOGIN_RATE_WINDOW,
                ],
                1
            );

            return (int) $result === 1;
        } catch (\Throwable) {
            // If Redis is down, allow the request (fail-open for login)
            return false;
        }
    }
}
