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
 * Tracks user consent for GDPR/COPPA/CCPA compliance.
 *
 * @property int    $id
 * @property string $ip_hash
 * @property int|null $user_id
 * @property string $consent_type     (age_verification|privacy_policy|data_processing|cookies)
 * @property string $policy_version
 * @property bool   $consented
 * @property string $created_at
 * @method static Consent|null find(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<Consent> query()
 * @method static Consent create(array<string, mixed> $attributes)
 */
class Consent extends Model
{
    protected ?string $table = 'consents';
    public bool $timestamps = false;

    /** @var string[] */
    protected array $fillable = [
        'ip_hash', 'user_id', 'consent_type', 'policy_version', 'consented',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'        => 'integer',
        'user_id'   => 'integer',
        'consented' => 'boolean',
    ];
}
