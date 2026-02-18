<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Consent;
use App\Model\DeletionRequest;
use App\Model\Session;
use App\Model\User;
use Hyperf\Redis\Redis;

final class AuthService
{
    private const SESSION_TTL = 86400 * 7; // 7 days

    public function __construct(
        private Redis $redis,
    ) {}

    /* ──────────────────────────────────────────────
     * Registration (Staff Only)
     * ────────────────────────────────────────────── */

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

    /**
     * Login
     * @return array<string, mixed>|null
     */
    public function login(string $username, string $password, string $ip, string $userAgent): ?array
    {
        $user = User::query()->where('username', $username)->first();
        if (!$user instanceof User || !password_verify($password, $user->password_hash)) {
            return null;
        }

        if ($user->banned) {
            $expired = $user->ban_expires_at && strtotime((string) $user->ban_expires_at) < time();
            if (!$expired) {
                throw new \RuntimeException('Account is banned: ' . $user->ban_reason);
            }
            // Unban if expired
            $user->update(['banned' => false, 'ban_reason' => null, 'ban_expires_at' => null]);
        }

        $token = bin2hex(random_bytes(32));

        // @phpstan-ignore-next-line
        $session = Session::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $token),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_TTL),
        ]);

        // Store in Redis for fast lookup
        $this->redis->setex(
            "session:{$token}",
            self::SESSION_TTL,
            (string) json_encode([
                'user_id'  => $user->id,
                'username' => $user->username,
                'role'     => $user->role,
            ])
        );

        return [
            'token'      => $token,
            'expires_in' => self::SESSION_TTL,
            'user'       => [
                'id'       => $user->id,
                'username' => $user->username,
                'role'     => $user->role,
            ],
        ];
    }

    /* ──────────────────────────────────────────────
     * Session Validation
     * ────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token): ?array
    {
        $cached = $this->redis->get("session:{$token}");
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            /** @var array<string, mixed>|null $decoded */
            return is_array($decoded) ? $decoded : null;
        }

        $hashed = hash('sha256', $token);
        $session = Session::query()->where('token', $hashed)->first();
        if (!$session instanceof Session || $session->isExpired()) {
            return null;
        }

        $user = User::find($session->user_id);
        if (!$user instanceof User) return null;

        $data = [
            'user_id'  => $user->id,
            'username' => $user->username,
            'role'     => $user->role,
        ];

        // Re-cache
        $remaining = max(1, strtotime((string) $session->expires_at) - time());
        $this->redis->setex("session:{$token}", $remaining, (string) json_encode($data));

        return $data;
    }

    /* ──────────────────────────────────────────────
     * Logout
     * ────────────────────────────────────────────── */

    public function logout(string $token): void
    {
        $this->redis->del("session:{$token}");
        $hashed = hash('sha256', $token);
        Session::query()->where('token', $hashed)->delete();
    }

    /* ──────────────────────────────────────────────
     * Ban management
     * ────────────────────────────────────────────── */

    public function banUser(int $userId, string $reason, ?string $expiresAt = null): void
    {
        $user = User::findOrFail($userId);
        $user->update([
            'banned'         => true,
            'ban_reason'     => $reason,
            'ban_expires_at' => $expiresAt,
        ]);

        // Invalidate all active sessions
        $sessions = Session::query()->where('user_id', $userId)->get();
        foreach ($sessions as $session) {
            // Can't recover raw token from hash, so just delete DB records
            $session->delete();
        }
    }

    public function unbanUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['banned' => false, 'ban_reason' => null, 'ban_expires_at' => null]);
    }

    /** Check if an IP is banned (for anonymous users). */
    public function isIpBanned(string $ipHash): bool
    {
        return (bool) $this->redis->get("ban:ip:{$ipHash}");
    }

    public function banIp(string $ipHash, string $reason, int $durationSeconds = 86400): void
    {
        $this->redis->setex("ban:ip:{$ipHash}", $durationSeconds, (string) json_encode([
            'reason'     => $reason,
            'banned_at'  => time(),
            'expires_at' => time() + $durationSeconds,
        ]));
    }

    /* ──────────────────────────────────────────────
     * Consent Tracking (GDPR / COPPA / CCPA)
     * ────────────────────────────────────────────── */

    public function recordConsent(string $ipHash, ?int $userId, string $type, string $policyVersion, bool $consented): Consent
    {
        // @phpstan-ignore-next-line
        return Consent::create([
            'ip_hash'        => $ipHash,
            'user_id'        => $userId,
            'consent_type'   => $type,
            'policy_version' => $policyVersion,
            'consented'      => $consented,
        ]);
    }

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
     * @return array<int, array<string, mixed>>
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
