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
 * Ban Request model - ported from OpenYotsuba ban_requests system.
 * 
 * Represents a janitor's request to ban a user, pending approval.
 * 
 * @property int        $id
 * @property string     $board             Board slug
 * @property int        $post_no           Post number
 * @property string     $janitor           Janitor username
 * @property int        $ban_template      Template ID
 * @property string     $post_json         Post JSON snapshot
 * @property string     $reason            Ban reason
 * @property string     $length            Requested ban length
 * @property string     $image_hash        Image hash (if applicable)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @method static \Hyperf\Database\Model\Builder<BanRequest> query()
 * @method static BanRequest|null find(mixed $id)
 * @method static BanRequest create(array<string, mixed> $attributes)
 */
class BanRequest extends Model
{
    protected ?string $table = 'ban_requests';

    /** @var array<int, string> */
    protected array $fillable = [
        'board',
        'post_no',
        'janitor',
        'ban_template',
        'post_json',
        'reason',
        'length',
        'image_hash',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'           => 'integer',
        'post_no'      => 'integer',
        'ban_template' => 'integer',
    ];

    /**
     * Get requests for a specific board
     *
     * @param \Hyperf\Database\Model\Builder<static> $query
     * @return \Hyperf\Database\Model\Builder<static>
     */
    public function scopeForBoard(
        \Hyperf\Database\Model\Builder $query,
        string $board
    ): \Hyperf\Database\Model\Builder {
        return $query->where('board', $board);
    }

    /**
     * Get requests by janitor
     *
     * @param \Hyperf\Database\Model\Builder<static> $query
     * @return \Hyperf\Database\Model\Builder<static>
     */
    public function scopeByJanitor(
        \Hyperf\Database\Model\Builder $query,
        string $janitor
    ): \Hyperf\Database\Model\Builder {
        return $query->where('janitor', $janitor);
    }

    /**
     * Get post data as array
     * @return array<string, mixed>
     */
    public function getPostData(): array
    {
        $json = $this->getAttribute('post_json');
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Get associated ban template
     *
     * @return \Hyperf\Database\Model\Relations\BelongsTo<\App\Model\BanTemplate, $this>
     */
    public function template(): \Hyperf\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(BanTemplate::class, 'ban_template');
    }
}
