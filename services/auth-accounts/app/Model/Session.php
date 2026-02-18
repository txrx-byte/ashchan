<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $user_id
 * @property string $token
 * @property string $ip_address
 * @property string $user_agent
 * @property string $expires_at
 * @property string $created_at
 * @method static Session|null find(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<Session> query()
 * @method static Session create(array<string, mixed> $attributes)
 */
class Session extends Model
{
    protected ?string $table = 'sessions';
    public bool $timestamps = false;

    /** @var string[] */
    protected array $fillable = [
        'user_id', 'token', 'ip_address', 'user_agent', 'expires_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'      => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }
}
