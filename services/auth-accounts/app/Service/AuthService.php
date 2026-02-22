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

use App\Model\Consent;
use App\Model\DeletionRequest;
use App\Model\Session;
use App\Model\User;
use Hyperf\Redis\Redis;

/**
 * Core authentication and identity service.
 *
 * Handles staff user lifecycle: registration, login/logout, session management,
 * ban enforcement, consent tracking, and GDPR/CCPA data rights.
 *
 * Security model:
 *  - Passwords hashed with Argon2id (memory-hard KDF)
 *  - Session tokens: raw 256-bit token to client, SHA-256 hash stored in DB
 *  - Redis cache for fast token validation with DB fallback
 *  - Ban state checked on every token validation (not just login)
 *  - IP addresses hashed with HMAC-SHA256 using a server-side secret
 *
 * Performance:
 *  - Session validation is O(1) via Redis (cache hit) or O(1) DB lookup (miss)
 *  - Bulk session deletion on ban avoids N+1 queries
 *  - Redis operations wrapped in try/catch for graceful degradation
 */
final class AuthService
{
    /** Session lifetime: 7 days in seconds. */
    private const SESSION_TTL = 86400 * 7;

    /** HMAC key for IP address hashing. Falls back to app-level secret. */
    private string $ipHmacKey;

    public function __construct(
        private Redis $redis,
        private PiiEncryptionService $piiEncryption,
    ) {
        // Use a dedicated HMAC key or fall back to PII_ENCRYPTION_KEY
        $hmacKey = \Hyperf\Support\env('IP_HMAC_KEY', '') ?: \Hyperf\Support\env('PII_ENCRYPTION_KEY', '');
        $this->ipHmacKey = is_string($hmacKey) && $hmacKey !== '' ? $hmacKey : 'ashchan-default-hmac-key';
    }

    /**
     * Expose the Redis instance for controller-level rate limiting.
     *
     * This avoids duplicating the Redis dependency in the controller. The controller
     * should only use this for rate-limiting operations, not general data access.
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /* ──────────────────────────────────────────────
     * Registration (Staff Only)
     * ────────────────────────────────────────────── */

    /**
     * Register a new staff user.
     *
     * @param string $username Unique username (pre-validated by controller)
     * @param string $password Plaintext password (hashed with Argon2id before storage)
     * @param string $email    Optional email address
     * @param string $role     One of: admin, manager, mod, janitor, user
     * @return User The created user model
     * @throws \RuntimeException If the username is already taken
     */
    public function register(string $username, string $password, string $email, string $role = 'user'): User
    {
        if (User::query()->where('username', $username)->exists()) {
            throw new \RuntimeException('Username already taken');
        }

        // @phpstan-ignore-next-line
        return User::create([
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'email'         => $email,
            'role'          => $role,
        ]);
    }

    /* ──────────────────────────────────────────────
     * Login
     * ────────────────────────────────────────────── */

    /**
     * Authenticate a user by username/password and create a session.
     *
     * Ban state is checked after password verification. Expired bans are
     * automatically cleared. Active bans throw a RuntimeException (the
     * controller should catch this and return a generic error to prevent
     * information leakage).
     *
     * @param string $username  The staff username
     * @param string $password  Plaintext password to verify
     * @param string $ip        Client IP (encrypted before storage, never stored raw)
     * @param string $userAgent Client User-Agent header
     * @return array<string, mixed>|null Token and user info on success, null on bad credentials
     * @throws \RuntimeException If the account is currently banned
     */
    public function login(string $username, string $password, string $ip, string $userAgent): ?array
    {
        $user = User::query()->where('username', $username)->first();

        if (!$user instanceof User) {
            // Perform a dummy password_verify to prevent timing-based user enumeration.
            // Without this, an attacker could distinguish "user not found" (fast)
            // from "wrong password" (slow due to Argon2id) by measuring response time.
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$dXNlckBhc2hjaGFu$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
            return null;
        }

        if (!password_verify($password, $user->password_hash)) {
            return null;
        }

        // Check ban status after credential verification
        if ($user->banned) {
            $expired = $user->ban_expires_at !== null
                && $user->ban_expires_at !== ''
                && strtotime((string) $user->ban_expires_at) < time();

            if (!$expired) {
                // Don't include ban reason — controller returns generic error
                throw new \RuntimeException('Account is banned');
            }

            // Auto-clear expired ban
            $user->update(['banned' => false, 'ban_reason' => null, 'ban_expires_at' => null]);
        }

        // Generate a cryptographically secure 256-bit session token
        $token = bin2hex(random_bytes(32));

        // @phpstan-ignore-next-line
        Session::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $token),
            'ip_address' => $this->piiEncryption->encrypt($ip),
            'user_agent' => mb_substr($userAgent, 0, 512),
            'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_TTL),
        ]);

        // Cache session in Redis for O(1) validation
        $sessionData = [
            'user_id'  => $user->id,
            'username' => $user->username,
            'role'     => $user->role,
        ];
        try {
            $this->redis->setex(
                "session:{$token}",
                self::SESSION_TTL,
                (string) json_encode($sessionData, JSON_THROW_ON_ERROR)
            );
        } catch (\Throwable) {
            // Redis failure is non-fatal — DB fallback will handle validation
        }

        return [
            'token'      => $token,
            'expires_in' => self::SESSION_TTL,
            'user'       => $sessionData,
        ];
    }

    /* ──────────────────────────────────────────────
     * Session Validation
     * ────────────────────────────────────────────── */

    /**
     * Validate a session token and return associated user data.
     *
     * Checks Redis cache first (O(1)), then falls back to DB lookup.
     * Always verifies the user's current ban status to ensure banned users
     * cannot continue operating with a previously-issued token.
     *
     * @param string $token The raw session token from the client
     * @return array<string, mixed>|null User data or null if invalid/expired/banned
     */
    public function validateToken(string $token): ?array
    {
        // Fast path: check Redis cache
        $cached = null;
        try {
            $cached = $this->redis->get("session:{$token}");
        } catch (\Throwable) {
            // Redis unavailable — fall through to DB
        }

        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (!is_array($decoded)) {
                return null;
            }

            // Re-verify ban status even for cached sessions to prevent
            // banned users from operating until the Redis TTL expires
            $userId = $decoded['user_id'] ?? null;
            if (is_int($userId) && $this->isUserBanned($userId)) {
                $this->logout($token);
                return null;
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        // Slow path: database lookup
        $hashed = hash('sha256', $token);
        $session = Session::query()->where('token', $hashed)->first();
        if (!$session instanceof Session || $session->isExpired()) {
            return null;
        }

        $user = User::find($session->user_id);
        if (!$user instanceof User) {
            return null;
        }

        // Check ban status from fresh DB data
        if ($user->banned) {
            $expired = $user->ban_expires_at !== null
                && $user->ban_expires_at !== ''
                && strtotime((string) $user->ban_expires_at) < time();

            if (!$expired) {
                // Delete the session — banned user shouldn't have active sessions
                $this->logout($token);
                return null;
            }
            // Auto-clear expired ban
            $user->update(['banned' => false, 'ban_reason' => null, 'ban_expires_at' => null]);
        }

        $data = [
            'user_id'  => $user->id,
            'username' => $user->username,
            'role'     => $user->role,
        ];

        // Re-cache with remaining TTL
        $remaining = max(1, strtotime((string) $session->expires_at) - time());
        try {
            $this->redis->setex("session:{$token}", $remaining, (string) json_encode($data, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Non-fatal
        }

        return $data;
    }

    /* ──────────────────────────────────────────────
     * Logout
     * ────────────────────────────────────────────── */

    /**
     * Destroy a session by its raw token.
     *
     * Removes from both Redis cache and the database.
     *
     * @param string $token The raw session token
     */
    public function logout(string $token): void
    {
        try {
            $this->redis->del("session:{$token}");
        } catch (\Throwable) {
            // Non-fatal if Redis is down
        }

        $hashed = hash('sha256', $token);
        Session::query()->where('token', $hashed)->delete();
    }

    /* ──────────────────────────────────────────────
     * Ban management
     * ────────────────────────────────────────────── */

    /**
     * Ban a user account and invalidate all their sessions.
     *
     * Session invalidation uses a bulk DELETE query instead of fetching and
     * deleting individually (N+1 fix). Note: Redis-cached sessions cannot
     * be invalidated by token hash alone, but validateToken() now checks
     * ban status on every call, so cached sessions are effectively invalidated.
     *
     * @param int         $userId    The user ID to ban
     * @param string      $reason    Human-readable ban reason (stored for audit)
     * @param string|null $expiresAt Optional ISO 8601 expiry timestamp
     */
    public function banUser(int $userId, string $reason, ?string $expiresAt = null): void
    {
        $user = User::findOrFail($userId);
        $user->update([
            'banned'         => true,
            'ban_reason'     => mb_substr($reason, 0, 500),
            'ban_expires_at' => $expiresAt,
        ]);

        // Bulk-delete all sessions for this user (fixes N+1 query issue).
        // We can't purge Redis keys because we only store the SHA-256 hash
        // in the DB, not the raw token. However, validateToken() now checks
        // the user's ban status on every call, so cached sessions are
        // effectively invalidated on next use.
        Session::query()->where('user_id', $userId)->delete();
    }

    /**
     * Remove a ban from a user account.
     *
     * @param int $userId The user ID to unban
     */
    public function unbanUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['banned' => false, 'ban_reason' => null, 'ban_expires_at' => null]);
    }

    /**
     * Check if a user is currently banned (DB lookup).
     *
     * Used during token validation to ensure banned users can't use cached sessions.
     *
     * @param int $userId The user ID to check
     * @return bool True if the user is currently banned and the ban hasn't expired
     */
    private function isUserBanned(int $userId): bool
    {
        $user = User::find($userId);
        if (!$user instanceof User || !$user->banned) {
            return false;
        }

        // Check if ban has expired
        if ($user->ban_expires_at !== null && $user->ban_expires_at !== '') {
            if (strtotime((string) $user->ban_expires_at) < time()) {
                // Auto-clear expired ban
                $user->update(['banned' => false, 'ban_reason' => null, 'ban_expires_at' => null]);
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an IP hash is banned (Redis lookup for anonymous users).
     *
     * @param string $ipHash HMAC-SHA256 hash of the IP address
     * @return bool True if the IP is currently banned
     */
    public function isIpBanned(string $ipHash): bool
    {
        try {
            return (bool) $this->redis->get("ban:ip:{$ipHash}");
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ban an IP address hash with a TTL.
     *
     * @param string $ipHash          HMAC-SHA256 hash of the IP
     * @param string $reason          Human-readable reason
     * @param int    $durationSeconds TTL in seconds (default 24h)
     */
    public function banIp(string $ipHash, string $reason, int $durationSeconds = 86400): void
    {
        try {
            $this->redis->setex("ban:ip:{$ipHash}", $durationSeconds, (string) json_encode([
                'reason'     => mb_substr($reason, 0, 500),
                'banned_at'  => time(),
                'expires_at' => time() + $durationSeconds,
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Log but don't fail — ban enforcement is best-effort if Redis is down
        }
    }

    /* ──────────────────────────────────────────────
     * IP Hashing
     * ────────────────────────────────────────────── */

    /**
     * Compute a deterministic, non-reversible hash of an IP address.
     *
     * Uses HMAC-SHA256 with a server-side secret key. Plain SHA-256 is insecure
     * for IP hashing because the IPv4 address space (2^32) is small enough
     * to brute-force with a rainbow table. HMAC with a secret key prevents this.
     *
     * @param string $ip The raw IP address
     * @return string The HMAC-SHA256 hex digest
     */
    public function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->ipHmacKey);
    }

    /* ──────────────────────────────────────────────
     * Consent Tracking (GDPR / COPPA / CCPA)
     * ────────────────────────────────────────────── */

    /**
     * Record a consent decision for compliance auditing.
     *
     * @param string   $ipHash        HMAC-SHA256 of the client IP
     * @param string   $ipEncrypted   Encrypted IP for admin recovery
     * @param int|null $userId        Associated user ID (null for anonymous)
     * @param string   $type          Consent type (privacy_policy, age_verification, etc.)
     * @param string   $policyVersion Version string of the policy consented to
     * @param bool     $consented     Whether consent was granted
     * @return Consent The created consent record
     */
    public function recordConsent(string $ipHash, string $ipEncrypted, ?int $userId, string $type, string $policyVersion, bool $consented): Consent
    {
        // @phpstan-ignore-next-line
        return Consent::create([
            'ip_hash'        => $ipHash,
            'ip_encrypted'   => $ipEncrypted,
            'user_id'        => $userId,
            'consent_type'   => $type,
            'policy_version' => $policyVersion,
            'consented'      => $consented,
        ]);
    }

    /**
     * Check if a given IP hash has granted a specific type of consent.
     *
     * @param string $ipHash HMAC-SHA256 of the IP
     * @param string $type   Consent type to check
     * @return bool True if active consent exists
     */
    public function hasConsent(string $ipHash, string $type): bool
    {
        return Consent::query()
            ->where('ip_hash', $ipHash)
            ->where('consent_type', $type)
            ->where('consented', true)
            ->exists();
    }

    /* ──────────────────────────────────────────────
     * Data Rights (GDPR / CCPA)
     * ────────────────────────────────────────────── */

    /**
     * Create a data export request for a user.
     *
     * @param int $userId The requesting user's ID
     * @return DeletionRequest The created request record
     */
    public function requestDataExport(int $userId): DeletionRequest
    {
        // @phpstan-ignore-next-line
        return DeletionRequest::create([
            'user_id'      => $userId,
            'status'       => 'pending',
            'request_type' => 'data_export',
            'requested_at' => \Carbon\Carbon::now(),
        ]);
    }

    /**
     * Create a data deletion request for a user (right to be forgotten).
     *
     * @param int $userId The requesting user's ID
     * @return DeletionRequest The created request record
     */
    public function requestDataDeletion(int $userId): DeletionRequest
    {
        // @phpstan-ignore-next-line
        return DeletionRequest::create([
            'user_id'      => $userId,
            'status'       => 'pending',
            'request_type' => 'data_deletion',
            'requested_at' => \Carbon\Carbon::now(),
        ]);
    }

    /**
     * Retrieve all data requests for a user, ordered newest-first.
     *
     * @param int $userId The user's ID
     * @return array<int, array<string, mixed>> Array of request records
     */
    public function getDataRequests(int $userId): array
    {
        /** @var array<int, array<string, mixed>> $data */
        $data = DeletionRequest::query()
            ->where('user_id', $userId)
            ->orderByDesc('requested_at')
            ->get()
            ->toArray();
        return $data;
    }
}
