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
 * When a user starts typing in a post form, an "open post" is allocated
 * with a unique ID. As the user types, their content is synced via WebSocket
 * and persisted to this table (not the main posts table) every ~1 second.
 *
 * When the post is closed (submitted or expired):
 * 1. Body is copied from open_post_bodies to posts.content
 * 2. Markup is parsed and content_html is generated
 * 3. This row is deleted
 *
 * This separation prevents excessive writes to the main posts table
 * during active editing sessions.
 *
 * Database table: `open_post_bodies`
 *
 * @property int $post_id Primary key (same as posts.id)
 * @property string $body Raw post body text (unparsed markup)
 * @property string $updated_at Timestamp of last body update
 *
 * @property-read \Hyperf\Database\Model\Relations\BelongsTo<Post, $this> $post
 *
 * @see docs/LIVEPOSTING.md §7.2
 * @see \App\Controller\LivepostController For liveposting API
 * @see \App\Service\BoardService::createOpenPost() For allocation
 * @see \App\Service\BoardService::closeOpenPost() For finalization
 * @see Post Parent post record
 */
class OpenPostBody extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'open_post_bodies';

    /**
     * The primary key for the model.
     *
     * Uses post_id as primary key (1:1 relationship with posts table).
     *
     * @var string
     */
    protected string $primaryKey = 'post_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * Post IDs are manually assigned from the posts_id_seq sequence.
     *
     * @var bool
     */
    public bool $incrementing = false;

    /**
     * The key type for the primary key.
     *
     * @var string
     */
    protected string $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     *
     * Only updated_at is used; no created_at for this table.
     *
     * @var bool
     */
    public bool $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected array $fillable = [
        'post_id',
        'body',
        'updated_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
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
