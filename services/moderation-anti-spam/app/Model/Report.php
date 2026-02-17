<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int    $id
 * @property int    $post_id
 * @property string $reason        (rule_violation|spam|illegal|other)
 * @property string $details
 * @property string $reporter_ip_hash
 * @property string $status        (pending|reviewed|resolved|dismissed)
 * @property int|null $reviewed_by
 * @property string $created_at
 * @property string $updated_at
 */
class Report extends Model
{
    protected ?string $table = 'reports';

    protected array $fillable = [
        'post_id', 'reason', 'details', 'reporter_ip_hash', 'status', 'reviewed_by',
    ];

    protected array $casts = [
        'id'          => 'integer',
        'post_id'     => 'integer',
        'reviewed_by' => 'integer',
    ];
}
