<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int    $id
 * @property int    $report_id
 * @property int    $moderator_id
 * @property string $action         (delete_post|delete_image|ban_user|ban_ip|warn|dismiss)
 * @property string $reason
 * @property string $created_at
 */
class ModerationDecision extends Model
{
    protected string $table = 'moderation_decisions';
    public bool $timestamps = false;

    protected array $fillable = [
        'report_id', 'moderator_id', 'action', 'reason',
    ];

    protected array $casts = [
        'id'           => 'integer',
        'report_id'    => 'integer',
        'moderator_id' => 'integer',
    ];
}
