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


namespace App\Service;

use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * AuthenticationService - Production-ready staff authentication.
 * Security thresholds are configured via site_settings (admin panel).
 */
final class AuthenticationService
{
    private LoggerInterface $logger;
    
    // Non-configurable structural constants
    private const SESSION_TOKEN_LENGTH = 64;
    private const CSRF_TOKEN_LENGTH = 32;

    // Admin-configurable security settings (loaded from site_settings)
    private int $maxLoginAttempts;
    private int $lockoutDurationMinutes;
    private int $sessionTimeoutHours;
    private int $csrfTokenExpiryHours;
    private int $sessionCacheTtl;
    
    private PiiEncryptionService $piiEncryption;
    private Redis $redis;

    public function __construct(LoggerFactory $loggerFactory, PiiEncryptionService $piiEncryption, Redis $redis, SiteConfigService $config)
    {
        $this->logger = $loggerFactory->get('auth');
        $this->piiEncryption = $piiEncryption;
        $this->redis = $redis;
        $this->maxLoginAttempts = $config->getInt('max_login_attempts', 5);
        $this->lockoutDurationMinutes = $config->getInt('lockout_duration_minutes', 30);
        $this->sessionTimeoutHours = $config->getInt('staff_session_timeout', 8);
        $this->csrfTokenExpiryHours = $config->getInt('csrf_token_expiry_hours', 24);
        $this->sessionCacheTtl = $config->getInt('session_cache_ttl', 60);
    }
    
    /**
     * Authenticate user and create session
     * 
     * @return array{success: bool, user?: array<string, mixed>, error?: string, lockout_remaining?: int, session_token?: string}
     */
    public function authenticate(string $username, string $password, string $ipAddress, string $userAgent): array
    {
        // Check rate limiting
        $rateLimitCheck = $this->checkRateLimit($ipAddress, $username);
        if (!$rateLimitCheck['allowed']) {
            $this->logLoginAttempt($ipAddress, $username, false, 'too_many_attempts', $userAgent);
            return [
                'success' => false,
                'error' => 'Too many failed login attempts. Please try again later.',
                'lockout_remaining' => $rateLimitCheck['remaining_seconds'] ?? 0,
            ];
        }
        
        // Find user
        $user = Db::table('staff_users')
            ->where('username', $username)
            ->orWhere('email', $username)
            ->first();
        
        if (!$user) {
            $this->logLoginAttempt($ipAddress, $username, false, 'invalid_username', $userAgent);
            $this->recordFailedAttempt($ipAddress, $username, $userAgent);
            return [
                'success' => false,
                'error' => 'Invalid credentials',
            ];
        }
        
        $user = (array) $user;
        
        // Check if account is active
        if (!$user['is_active']) {
            $this->logLoginAttempt($ipAddress, $username, false, 'account_inactive', $userAgent);
            return [
                'success' => false,
                'error' => 'Account is disabled. Contact an administrator.',
            ];
        }
        
        // Check if account is locked
        if ($user['is_locked']) {
            $lockedUntil = strtotime((string) $user['locked_until']);
            if ($lockedUntil > time()) {
                $this->logLoginAttempt($ipAddress, $username, false, 'account_locked', $userAgent);
                return [
                    'success' => false,
                    'error' => 'Account is temporarily locked. Please try again later.',
                    'lockout_remaining' => $lockedUntil - time(),
                ];
            } else {
                // Auto-unlock expired lockout
                $this->unlockAccount((int) $user['id']);
            }
        }
        
        // Verify password
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->logLoginAttempt($ipAddress, $username, false, 'invalid_password', $userAgent);
            $this->recordFailedAttempt($ipAddress, $username, $userAgent);
            
            // Check if we should lock the account
            $this->checkAndLockAccount((int) $user['id']);
            
            return [
                'success' => false,
                'error' => 'Invalid credentials',
            ];
        }
        
        // Password is correct - reset failed attempts
        $this->resetFailedAttempts((int) $user['id']);
        
        // Check if password needs rehash (cost increased)
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->updatePasswordHash((int) $user['id'], $password);
        }
        
        // Create session
        $sessionToken = $this->createSession((int) $user['id'], $ipAddress, $userAgent);
        
        // Update user last login
        $this->updateLastLogin((int) $user['id'], $ipAddress, $userAgent);
        
        // Log successful login
        $this->logLoginAttempt($ipAddress, $username, true, null, $userAgent);
        $this->logAuditAction((int) $user['id'], $username, 'login', 'system', null, null, 'User logged in', $ipAddress, $userAgent);
        
        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'access_level' => $user['access_level'],
                'access_flags' => $user['access_flags'] ?? [],
                'board_access' => $user['board_access'] ?? [],
            ],
            'session_token' => $sessionToken,
        ];
    }
    
    /**
     * Validate session token and return user info
     * 
     * Uses Redis to cache validated sessions to avoid DB hits on every request.
     * 
     * @return array{valid: bool, user?: array<string, mixed>}
     */
    public function validateSession(string $tokenHash): array
    {
        // Check Redis cache first
        $cacheKey = 'session:validated:' . $tokenHash;
        try {
            $cached = $this->redis->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && ($decoded['valid'] ?? false)) {
                    /** @var array{valid: bool, user?: array<string, mixed>} $decoded */
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // Redis down — fall through to DB
        }

        $session = Db::table('staff_sessions')
            ->where('token_hash', $tokenHash)
            ->where('is_valid', true)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();
        
        if (!$session) {
            return ['valid' => false];
        }
        
        $user = Db::table('staff_users')
            ->where('id', $session->user_id)
            ->where('is_active', true)
            ->first();
        
        if (!$user) {
            // Invalidate session if user is inactive
            $this->invalidateSession($tokenHash, 'user_inactive');
            return ['valid' => false];
        }
        
        // Throttle last_activity update to once per 30 seconds (avoid DB write on every request)
        $activityKey = 'session:activity:' . $tokenHash;
        try {
            if (!$this->redis->exists($activityKey)) {
                Db::table('staff_sessions')
                    ->where('token_hash', $tokenHash)
                    ->update(['last_activity' => date('Y-m-d H:i:s')]);
                $this->redis->setex($activityKey, 30, '1');
            }
        } catch (\Throwable) {
            // Best effort — if Redis is down, always update DB
            Db::table('staff_sessions')
                ->where('token_hash', $tokenHash)
                ->update(['last_activity' => date('Y-m-d H:i:s')]);
        }
        
        $result = [
            'valid' => true,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'access_level' => $user->access_level,
                'access_flags' => $user->access_flags ?? [],
                'board_access' => $user->board_access ?? [],
            ],
        ];

        // Cache the validated session
        try {
            $this->redis->setex($cacheKey, $this->sessionCacheTtl, (string) json_encode($result));
        } catch (\Throwable) {
            // Non-critical
        }

        return $result;
    }
    
    /**
     * Logout and invalidate session
     */
    public function logout(string $tokenHash, int $userId, string $ipAddress = '', string $userAgent = ''): void
    {
        $this->invalidateSession($tokenHash, 'logout');
        
        $user = Db::table('staff_users')->where('id', $userId)->first();
        if ($user) {
            $this->logAuditAction(
                $userId,
                (string) $user->username,
                'logout',
                'system',
                null,
                null,
                'User logged out',
                $ipAddress,
                $userAgent
            );
        }
    }
    
    /**
     * Generate CSRF token for forms
     */
    public function generateCsrfToken(int $userId): string
    {
        $token = bin2hex(random_bytes(self::CSRF_TOKEN_LENGTH));
        $tokenHash = hash('sha256', $token);
        
        Db::table('csrf_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->csrfTokenExpiryHours * 3600)),
        ]);
        
        // Throttle cleanup: only purge expired tokens every ~5 minutes (probabilistic)
        if (random_int(1, 100) <= 5) {
            $this->cleanupExpiredCsrfTokens();
        }
        
        return $token;
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCsrfToken(int $userId, string $token): bool
    {
        $tokenHash = hash('sha256', $token);
        
        $tokenRecord = Db::table('csrf_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->where('used', false)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();
        
        if (!$tokenRecord) {
            return false;
        }
        
        // Mark token as used
        Db::table('csrf_tokens')
            ->where('id', $tokenRecord->id)
            ->update([
                'used' => true,
                'used_at' => date('Y-m-d H:i:s'),
            ]);
        
        return true;
    }
    
    /**
     * Check if user has permission
     */
    public function hasPermission(int $userId, string $permission): bool
    {
        $user = Db::table('staff_users')->where('id', $userId)->first();
        
        if (!$user || !$user->is_active) {
            return false;
        }
        
        // Check if user has all permissions flag
        if (in_array('all', (array) ($user->access_flags ?? []))) {
            return true;
        }
        
        // Check specific permission
        return in_array($permission, (array) ($user->access_flags ?? []));
    }
    
    /**
     * Check if user has access level
     */
    public function hasAccessLevel(int $userId, string $requiredLevel): bool
    {
        $levels = [
            'janitor' => 1,
            'mod' => 2,
            'manager' => 3,
            'admin' => 4,
        ];
        
        $user = Db::table('staff_users')->where('id', $userId)->first();
        
        if (!$user || !$user->is_active) {
            return false;
        }
        
        $userLevel = $levels[$user->access_level] ?? 0;
        $requiredLevelNum = $levels[$requiredLevel] ?? 0;
        
        return $userLevel >= $requiredLevelNum;
    }
    
    /**
     * Check if user can access board
     */
    public function canAccessBoard(int $userId, string $board): bool
    {
        $user = Db::table('staff_users')->where('id', $userId)->first();
        
        if (!$user) {
            return false;
        }
        
        // Empty board_access means all boards
        if (empty($user->board_access)) {
            return true;
        }
        
        return in_array($board, (array) ($user->board_access ?? []));
    }
    
    /**
     * Log audit action
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function logAuditAction(
        int $userId,
        string $username,
        string $actionType,
        string $category,
        ?string $resourceType,
        ?int $resourceId,
        string $description,
        string $ipAddress,
        string $userAgent,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $requestUri = '',
        ?string $board = null
    ): void {
        Db::table('admin_audit_log')->insert([
            'user_id' => $userId,
            'username' => $username,
            'action_type' => $actionType,
            'action_category' => $category,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'description' => $description,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => $this->piiEncryption->encrypt($ipAddress),
            'user_agent' => $userAgent,
            'request_uri' => $requestUri,
            'board' => $board,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Get user permissions
     * 
     * @return string[]
     */
    public function getUserPermissions(int $userId): array
    {
        $permissions = Db::table('staff_users as u')
            ->select('p.permission_name')
            ->join('access_levels as al', 'u.access_level', '=', 'al.level_name')
            ->leftJoin('level_permissions as lp', 'al.level_name', '=', 'lp.level_name')
            ->leftJoin('permissions as p', 'lp.permission_id', '=', 'p.id')
            ->where('u.id', $userId)
            ->where('u.is_active', true)
            ->pluck('permission_name');
        
        return array_map(static fn (mixed $v): string => (string) $v, $permissions->toArray());
    }
    
    /**
     * Create session token
     */
    private function createSession(int $userId, string $ipAddress, string $userAgent): string
    {
        $token = bin2hex(random_bytes(self::SESSION_TOKEN_LENGTH));
        $tokenHash = hash('sha256', $token);
        
        // Invalidate old sessions for this user
        $this->invalidateOldSessions($userId);
        
        Db::table('staff_sessions')->insert([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'ip_address' => $this->piiEncryption->encrypt($ipAddress),
            'user_agent' => $userAgent,
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->sessionTimeoutHours * 3600)),
        ]);
        
        // Update user's current session
        Db::table('staff_users')
            ->where('id', $userId)
            ->update([
                'current_session_token' => $tokenHash,
                'session_expires_at' => date('Y-m-d H:i:s', time() + ($this->sessionTimeoutHours * 3600)),
            ]);
        
        return $token;
    }
    
    /**
     * Invalidate session
     */
    private function invalidateSession(string $tokenHash, string $reason): void
    {
        Db::table('staff_sessions')
            ->where('token_hash', $tokenHash)
            ->update([
                'is_valid' => false,
                'invalidated_at' => date('Y-m-d H:i:s'),
                'invalidate_reason' => $reason,
            ]);
        
        // Also clear from user record
        Db::table('staff_users')
            ->where('current_session_token', $tokenHash)
            ->update([
                'current_session_token' => null,
                'session_expires_at' => null,
            ]);

        // Clear session cache in Redis
        try {
            $this->redis->del('session:validated:' . $tokenHash);
            $this->redis->del('session:activity:' . $tokenHash);
        } catch (\Throwable) {
            // Non-critical
        }
    }
    
    /**
     * Invalidate old sessions for user
     */
    private function invalidateOldSessions(int $userId): void
    {
        // Get max concurrent sessions setting
        $settings = Db::table('staff_security_settings')
            ->where('user_id', $userId)
            ->first();
        
        $maxSessions = (int) ($settings->max_concurrent_sessions ?? 3);
        
        // Keep only the most recent sessions
        $oldSessions = Db::table('staff_sessions')
            ->where('user_id', $userId)
            ->where('is_valid', true)
            ->orderBy('created_at', 'desc')
            ->offset($maxSessions)
            ->pluck('token_hash');
        
        foreach ($oldSessions as $tokenHash) {
            $this->invalidateSession((string) $tokenHash, 'replaced');
        }
    }
    
    /**
     * Check rate limiting
     * 
     * @return array{allowed: bool, remaining_seconds?: int}
     */
    private function checkRateLimit(string $ipAddress, string $username): array
    {
        $ipHash = hash('sha256', $ipAddress);
        
        // Check recent failed attempts from this IP
        $recentFailures = Db::table('login_attempts')
            ->where('ip_address_hash', $ipHash)
            ->where('success', false)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 900)) // Last 15 minutes
            ->count();
        
        if ($recentFailures >= 20) {
            return ['allowed' => false, 'remaining_seconds' => 900];
        }
        
        // Check failed attempts for this username
        $usernameFailures = Db::table('login_attempts')
            ->where('username_attempted', $username)
            ->where('success', false)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 900))
            ->count();
        
        if ($usernameFailures >= $this->maxLoginAttempts) {
            return ['allowed' => false, 'remaining_seconds' => 900];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Record failed login attempt
     */
    private function recordFailedAttempt(string $ipAddress, string $username, string $userAgent): void
    {
        Db::table('login_attempts')->insert([
            'ip_address' => $this->piiEncryption->encrypt($ipAddress),
            'ip_address_hash' => hash('sha256', $ipAddress),
            'username_attempted' => $username,
            'success' => false,
            'user_agent' => $userAgent,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Log login attempt
     */
    private function logLoginAttempt(string $ipAddress, string $username, bool $success, ?string $failureReason, string $userAgent): void
    {
        $level = $success ? 'info' : 'warning';
        $message = $success 
            ? "Successful login for user: {$username}"
            : "Failed login attempt for user: {$username} - {$failureReason}";
        
        // Never log raw IPs — use a one-way hash for log correlation only
        $this->logger->$level($message, [
            'ip_hash' => hash('sha256', $ipAddress),
            'username' => $username,
            'success' => $success,
            'failure_reason' => $failureReason,
        ]);
    }
    
    /**
     * Check and lock account if too many failed attempts
     */
    private function checkAndLockAccount(int $userId): void
    {
        $user = Db::table('staff_users')->where('id', $userId)->first();
        
        if (!$user) {
            return;
        }
        
        $newFailedCount = (int) ($user->failed_login_attempts ?? 0) + 1;
        
        if ($newFailedCount >= $this->maxLoginAttempts) {
            // Lock account
            Db::table('staff_users')
                ->where('id', $userId)
                ->update([
                    'is_locked' => true,
                    'locked_until' => date('Y-m-d H:i:s', time() + ($this->lockoutDurationMinutes * 60)),
                    'failed_login_attempts' => $newFailedCount,
                ]);
        } else {
            // Just increment counter
            Db::table('staff_users')
                ->where('id', $userId)
                ->update(['failed_login_attempts' => $newFailedCount]);
        }
    }
    
    /**
     * Reset failed login attempts
     */
    private function resetFailedAttempts(int $userId): void
    {
        Db::table('staff_users')
            ->where('id', $userId)
            ->update([
                'failed_login_attempts' => 0,
                'is_locked' => false,
                'locked_until' => null,
            ]);
    }
    
    /**
     * Unlock account
     */
    private function unlockAccount(int $userId): void
    {
        Db::table('staff_users')
            ->where('id', $userId)
            ->update([
                'is_locked' => false,
                'locked_until' => null,
                'failed_login_attempts' => 0,
            ]);
    }
    
    /**
     * Update password hash
     */
    private function updatePasswordHash(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        
        Db::table('staff_users')
            ->where('id', $userId)
            ->update(['password_hash' => $hash]);
    }
    
    /**
     * Update last login info
     */
    private function updateLastLogin(int $userId, string $ipAddress, string $userAgent): void
    {
        Db::table('staff_users')
            ->where('id', $userId)
            ->update([
                'last_login_at' => date('Y-m-d H:i:s'),
                'last_login_ip' => $this->piiEncryption->encrypt($ipAddress),
                'last_user_agent' => $userAgent,
            ]);
    }

    /**
     * Delete expired or used CSRF tokens.
     *
     * Called probabilistically during token generation and deterministically
     * by the csrf:cleanup console command.
     *
     * @return int Number of deleted rows
     */
    public function cleanupExpiredCsrfTokens(): int
    {
        return Db::table('csrf_tokens')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->orWhere('used', true)
            ->delete();
    }
}
