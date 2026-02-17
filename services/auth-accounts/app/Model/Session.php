<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int    $id
 * @property int    $user_id
 * @property string $token
 * @property string $ip_address
 * @property string $user_agent
 * @property string $expires_at
 * @property string $created_at
 */
class Session extends Model
{
    protected string $table = 'sessions';
    public bool $timestamps = false;

    protected array $fillable = [
        'user_id', 'token', 'ip_address', 'user_agent', 'expires_at',
    ];

    protected array $casts = [
        'id'      => 'integer',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }
}
