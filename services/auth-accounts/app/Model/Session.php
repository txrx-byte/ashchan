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
