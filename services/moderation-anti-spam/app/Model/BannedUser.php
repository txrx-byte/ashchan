<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * Banned User model - ported from OpenYotsuba bans system.
 * 
 * Represents an active or expired ban on a user/IP/pass.
 * 
 * @property int        $id
 * @property string     $board             Board slug (empty for global)
 * @property int        $global            1=global ban, 0=board-specific
 * @property int        $zonly             1=unappealable ban
 * @property string     $name              Display name (usually "Anonymous")
 * @property string     $host              Banned IP (encrypted)
 * @property string     $reverse           Reverse DNS
 * @property string     $xff              X-Forwarded-For header
 * @property string     $reason            Ban reason (public)
 * @property string     $length            Ban expiration timestamp
 * @property \Carbon\Carbon $now           Ban start timestamp
 * @property string     $admin             Staff who issued ban
 * @property string     $md5               File MD5 (for file bans)
 * @property int        $post_num          Post number associated
 * @property string     $rule              Rule violated
 * @property string     $post_time         Post timestamp
 * @property int        $template_id       Ban template ID used
 * @property string     $password          Password hash (for pass bans)
 * @property string     $pass_id           4chan Pass ID
 * @property string     $post_json         Post JSON snapshot
 * @property string     $admin_ip          Admin IP who issued ban
 * @property int        $active            1=active, 0=expired/removed
 * @property int        $appealable        1=can appeal
 * @property string|null $unbannedon       Unban timestamp
 * @property string     $ban_reason        Internal ban reason
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @method static \Hyperf\Database\Model\Builder<BannedUser> query()
 * @method static BannedUser|null find(mixed $id)
 * @method static BannedUser create(array<string, mixed> $attributes)
 */
class BannedUser extends Model
{
    protected ?string $table = 'banned_users';

    /** @var array<int, string> */
    protected array $fillable = [
        'board',
        'global',
        'zonly',
        'name',
        'host',
        'reverse',
        'xff',
        'reason',
        'length',
        'now',
        'admin',
        'md5',
        'post_num',
        'rule',
        'post_time',
        'template_id',
        'password',
        'pass_id',
        'post_json',
        'admin_ip',
        'active',
        'appealable',
        'unbannedon',
        'ban_reason',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'          => 'integer',
        'global'      => 'integer',
        'zonly'       => 'integer',
        'post_num'    => 'integer',
        'template_id' => 'integer',
        'active'      => 'integer',
        'appealable'  => 'integer',
        'now'         => 'datetime',
        'length'      => 'datetime',
        'unbannedon'  => 'datetime',
    ];

    /**
     * Ban types
     */
    public const TYPE_LOCAL = 'local';
    public const TYPE_GLOBAL = 'global';
    public const TYPE_ZONLY = 'zonly';

    /**
     * Get active bans only
     */
    public function scopeActive(\Hyperf\Database\Model\Builder $query): \Hyperf\Database\Model\Builder
    {
        return $query->where('active', 1)
                     ->where('length', '>', now());
    }

    /**
     * Get bans for a specific board
     */
    public function scopeForBoard(
        \Hyperf\Database\Model\Builder $query,
        string $board
    ): \Hyperf\Database\Model\Builder {
        return $query->where(function ($q) use ($board) {
            $q->where('global', 1)
              ->orWhere('board', $board);
        });
    }

    /**
     * Get global bans only
     */
    public function scopeGlobal(\Hyperf\Database\Model\Builder $query): \Hyperf\Database\Model\Builder
    {
        return $query->where('global', 1);
    }

    /**
     * Get bans by IP
     */
    public function scopeByIp(
        \Hyperf\Database\Model\Builder $query,
        string $ip
    ): \Hyperf\Database\Model\Builder {
        return $query->where('host', $ip);
    }

    /**
     * Get bans by 4chan Pass ID
     */
    public function scopeByPassId(
        \Hyperf\Database\Model\Builder $query,
        string $passId
    ): \Hyperf\Database\Model\Builder {
        return $query->where('pass_id', $passId);
    }

    /**
     * Check if ban is expired
     */
    public function isExpired(): bool
    {
        $length = $this->getAttribute('length');
        if (!$length) {
            return false;
        }
        return $length instanceof \Carbon\Carbon && $length->isPast();
    }

    /**
     * Check if ban is a warning (very short duration)
     */
    public function isWarning(): bool
    {
        $now = $this->getAttribute('now');
        $length = $this->getAttribute('length');

        if (!$now || !$length) {
            return false;
        }

        $duration = $length->diffInSeconds($now);
        return $duration <= 10; // 10 seconds or less = warning
    }

    /**
     * Get remaining ban time in seconds
     */
    public function getRemainingSeconds(): int
    {
        $length = $this->getAttribute('length');
        if (!$length) {
            return 0;
        }

        $remaining = $length->diffInSeconds(now(), false);
        return max(0, $remaining);
    }

    /**
     * Get ban summary for API response
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'board' => $this->getAttribute('board'),
            'global' => (bool) $this->getAttribute('global'),
            'reason' => $this->getAttribute('reason'),
            'created_at' => $this->getAttribute('now')?->toIso8601String(),
            'expires_at' => $this->getAttribute('length')?->toIso8601String(),
            'is_warning' => $this->isWarning(),
            'is_permanent' => $this->getAttribute('length') === null,
            'remaining_seconds' => $this->getRemainingSeconds(),
        ];
    }
}
