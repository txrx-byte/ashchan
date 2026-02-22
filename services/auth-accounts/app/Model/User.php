<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * Staff user account model.
 *
 * Stores authentication credentials and ban state. The password_hash column
 * uses Argon2id and is excluded from serialization via $hidden.
 *
 * @property int         $id
 * @property string      $username
 * @property string      $password_hash
 * @property string      $email
 * @property string      $role           One of: admin, manager, mod, janitor, user
 * @property bool        $banned
 * @property string|null $ban_reason
 * @property string|null $ban_expires_at ISO 8601 timestamp or null for permanent bans
 * @property string      $created_at
 * @property string      $updated_at
 * @method static User|null find(mixed $id)
 * @method static User findOrFail(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<User> query()
 * @method static User create(array<string, mixed> $attributes)
 */
final class User extends Model
{
    protected ?string $table = 'users';

    /** @var string[] Columns that may be mass-assigned. */
    protected array $fillable = [
        'username', 'password_hash', 'email', 'role',
        'banned', 'ban_reason', 'ban_expires_at',
    ];

    /** @var array<string, string> Column type casts. */
    protected array $casts = [
        'id'     => 'integer',
        'banned' => 'boolean',
    ];

    /** @var string[] Columns hidden from JSON/array serialization to prevent credential leakage. */
    protected array $hidden = ['password_hash'];
}
