<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int    $id
 * @property string $username
 * @property string $password_hash
 * @property string $email
 * @property string $role          (admin|moderator|janitor|user)
 * @property bool   $banned
 * @property string $ban_reason
 * @property string $ban_expires_at
 * @property string $created_at
 * @property string $updated_at
 * @method static User|null find(mixed $id)
 * @method static User findOrFail(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<User> query()
 * @method static User create(array<string, mixed> $attributes)
 */
class User extends Model
{
    protected ?string $table = 'users';

    /** @var string[] */
    protected array $fillable = [
        'username', 'password_hash', 'email', 'role',
        'banned', 'ban_reason', 'ban_expires_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'     => 'integer',
        'banned' => 'boolean',
    ];

    /** @var string[] */
    protected array $hidden = ['password_hash'];
}
