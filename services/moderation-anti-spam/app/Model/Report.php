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
 * @method static Report|null find(mixed $id)
 * @method static Report findOrFail(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<Report> query()
 * @method static Report create(array<string, mixed> $attributes)
 */
class Report extends Model
{
    protected ?string $table = 'reports';

    /** @var array<int, string> */
    protected array $fillable = [
        'post_id', 'reason', 'details', 'reporter_ip_hash', 'status', 'reviewed_by',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'          => 'integer',
        'post_id'     => 'integer',
        'reviewed_by' => 'integer',
    ];
}
