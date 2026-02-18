<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * GDPR/CCPA data deletion request.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $status     (pending|processing|completed|denied)
 * @property string $request_type (data_export|data_deletion)
 * @property string $requested_at
 * @property string $completed_at
 * @method static DeletionRequest|null find(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<DeletionRequest> query()
 * @method static DeletionRequest create(array<string, mixed> $attributes)
 */
class DeletionRequest extends Model
{
    protected ?string $table = 'deletion_requests';
    public bool $timestamps = false;

    /** @var string[] */
    protected array $fillable = [
        'user_id', 'status', 'request_type', 'requested_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'      => 'integer',
        'user_id' => 'integer',
    ];
}
