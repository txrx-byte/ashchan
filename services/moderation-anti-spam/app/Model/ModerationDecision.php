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
 * @property int    $id
 * @property int    $report_id
 * @property string $moderator_id
 * @property string $target_type
 * @property string $target_id
 * @property string $action         (delete_post|delete_image|ban_user|ban_ip|warn|dismiss)
 * @property string $reason
 * @property string $metadata
 * @property string $created_at
 * @method static ModerationDecision|null find(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<ModerationDecision> query()
 * @method static ModerationDecision create(array<string, mixed> $attributes)
 */
class ModerationDecision extends Model
{
    protected ?string $table = 'moderation_decisions';
    public bool $timestamps = false;

    /** @var array<int, string> */
    protected array $fillable = [
        'moderator_id', 'target_type', 'target_id', 'action', 'reason', 'metadata',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'metadata' => 'json',
    ];
}
