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
 */
class DeletionRequest extends Model
{
    protected string $table = 'deletion_requests';
    public bool $timestamps = false;

    protected array $fillable = [
        'user_id', 'status', 'request_type', 'requested_at',
    ];

    protected array $casts = [
        'id'      => 'integer',
        'user_id' => 'integer',
    ];
}
