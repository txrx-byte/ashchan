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
 */
class User extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = [
        'username', 'password_hash', 'email', 'role',
        'banned', 'ban_reason', 'ban_expires_at',
    ];

    protected array $casts = [
        'id'     => 'integer',
        'banned' => 'boolean',
    ];

    protected array $hidden = ['password_hash'];
}
