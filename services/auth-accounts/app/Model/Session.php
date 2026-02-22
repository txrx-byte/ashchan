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
 * Authenticated session record.
 *
 * Stores the SHA-256 hash of the raw session token (the raw token is never
 * persisted). IP addresses are encrypted via PiiEncryptionService.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $token       SHA-256 hash of the raw session token
 * @property string $ip_address  Encrypted client IP (PII)
 * @property string $user_agent  Client User-Agent string
 * @property string $expires_at  ISO 8601 expiry timestamp
 * @property string $created_at
 * @method static Session|null find(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<Session> query()
 * @method static Session create(array<string, mixed> $attributes)
 */
final class Session extends Model
{
    protected ?string $table = 'sessions';
    public bool $timestamps = false;

    /** @var string[] Columns that may be mass-assigned. */
    protected array $fillable = [
        'user_id', 'token', 'ip_address', 'user_agent', 'expires_at',
    ];

    /** @var array<string, string> Column type casts. */
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

    /**
     * Check whether this session has passed its expiry time.
     *
     * BUG FIX: strtotime() can return false for malformed dates, which in PHP 8
     * would always compare as less-than time() (false < int is true). We now
     * treat unparseable expiry dates as expired to fail safely.
     */
    public function isExpired(): bool
    {
        $expiresTimestamp = strtotime($this->expires_at);

        // If the expiry date cannot be parsed, treat the session as expired
        // to prevent indefinitely-valid sessions from malformed data.
        if ($expiresTimestamp === false) {
            return true;
        }

        return $expiresTimestamp < time();
    }
}
