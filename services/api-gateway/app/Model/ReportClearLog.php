<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * Report Clear Log model - ported from OpenYotsuba report_clear_log.
 * 
 * Tracks when reports are cleared, used for abuse detection.
 * 
 * @property int        $id
 * @property string     $ip                Reporter IP (encrypted)
 * @property string|null $pwd              Reporter password hash
 * @property string|null $pass_id          4chan Pass ID
 * @property int        $category          Report category ID
 * @property float      $weight            Report weight
 * @property \Carbon\Carbon $created_at
 * 
 * @method static \Hyperf\Database\Model\Builder<ReportClearLog> query()
 * @method static ReportClearLog create(array<string, mixed> $attributes)
 */
class ReportClearLog extends Model
{
    protected ?string $table = 'report_clear_log';

    /** @var array<int, string> */
    protected array $fillable = [
        'ip',
        'pwd',
        'pass_id',
        'category',
        'weight',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'       => 'integer',
        'category' => 'integer',
        'weight'   => 'float',
    ];

    public bool $timestamps = false;

    /**
     * Get clear logs for an IP within a time range
     *
     * @param \Hyperf\Database\Model\Builder<static> $query
     * @return \Hyperf\Database\Model\Builder<static>
     */
    public function scopeForIpInRange(
        \Hyperf\Database\Model\Builder $query,
        string $ip,
        int $days
    ): \Hyperf\Database\Model\Builder {
        return $query->where('ip', $ip)
                     ->where('created_at', '>', now()->subDays($days));
    }

    /**
     * Count clears for abuse detection
     *
     * @param \Hyperf\Database\Model\Builder<static> $query
     * @return \Hyperf\Database\Model\Builder<static>
     */
    public function scopeCountForAbuseCheck(
        \Hyperf\Database\Model\Builder $query,
        string $ip,
        ?string $pwd = null,
        ?string $passId = null,
        int $days = 2
    ): \Hyperf\Database\Model\Builder {
        return $query->where(function ($q) use ($ip, $pwd, $passId) {
            $q->where('ip', $ip);
            
            if ($pwd !== null) {
                $q->orWhere('pwd', $pwd);
            }
            
            if ($passId !== null) {
                $q->orWhere('pass_id', $passId);
            }
        })
        ->where('created_at', '>', now()->subDays($days));
    }
}
