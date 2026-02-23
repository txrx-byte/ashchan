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
 * Rapidly-changing open post body, stored separately from the main
 * posts table to avoid write amplification during liveposting.
 *
 * When a post is closed, the body is copied to posts.content and
 * this row is deleted.
 *
 * @property int    $post_id
 * @property string $body
 * @property string $updated_at
 *
 * @see docs/LIVEPOSTING.md §7.2
 */
class OpenPostBody extends Model
{
    protected ?string $table = 'open_post_bodies';

    protected string $primaryKey = 'post_id';

    public bool $incrementing = false;

    /** @var string */
    protected string $keyType = 'int';

    public bool $timestamps = false;

    /** @var array<string> */
    protected array $fillable = [
        'post_id',
        'body',
        'updated_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'post_id' => 'integer',
    ];

    /**
     * Get the parent post.
     *
     * @return \Hyperf\Database\Model\Relations\BelongsTo<Post, $this>
     */
    public function post(): \Hyperf\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
