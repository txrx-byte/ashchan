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
 * Imageboard thread model.
 *
 * Represents a discussion thread within a board.
 * Threads are containers for posts (OP + replies) and track
 * bump order, reply counts, and moderation state.
 *
 * Database table: `threads`
 *
 * Thread Lifecycle:
 * 1. Created via ThreadController::create() with OP post
 * 2. Bumped (moved to top) when users reply (unless sage)
 * 3. May be stickied (pinned) or locked by staff
 * 4. Archived after inactivity or manual action
 * 5. Eventually pruned if board exceeds max_threads
 *
 * Bump Mechanics:
 * - bumped_at is updated on each reply (unless email = "sage")
 * - Threads sorted by bumped_at DESC (sticky first)
 * - Thread stops bumping at bump_limit replies
 *
 * @property int $id Primary key (same as OP post ID)
 * @property int $board_id Foreign key to boards table
 * @property bool $sticky Whether thread is pinned to top
 * @property bool $locked Whether thread is closed to replies
 * @property bool $archived Whether thread is archived (read-only)
 * @property int $reply_count Total number of replies (excluding OP)
 * @property int $image_count Total number of images in thread
 * @property string $bumped_at Timestamp of last bump
 * @property string $created_at Timestamp when thread was created
 * @property string $updated_at Timestamp when thread was last updated
 *
 * @property-read \Hyperf\Database\Model\Relations\BelongsTo<Board, $this> $board
 * @property-read \Hyperf\Database\Model\Relations\HasMany<Post, $this> $posts
 * @property-read \Hyperf\Database\Model\Relations\HasOne<Post, $this> $op
 *
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static static|null find(mixed $id, array<string> $columns = ['*'])
 * @method static \Hyperf\Database\Model\Builder<Thread> query()
 *
 * @see \App\Controller\ThreadController For thread API
 * @see \App\Service\BoardService For thread business logic
 * @see Board Parent board
 * @see Post Posts within this thread
 */
class Thread extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'threads';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * Thread IDs are manually assigned from the posts_id_seq sequence
     * (same sequence as post IDs to ensure global uniqueness).
     *
     * @var bool
     */
    public bool $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected array $fillable = [
        'id', 'board_id', 'sticky', 'locked', 'archived',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * Note: reply_count and image_count are updated via denormalization
     * for performance (avoiding COUNT(*) queries).
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'id'          => 'integer',
        'board_id'    => 'integer',
        'sticky'      => 'boolean',
        'locked'      => 'boolean',
        'archived'    => 'boolean',
        'reply_count' => 'integer',
        'image_count' => 'integer',
    ];

    /**
     * Get the parent board.
     *
     * @return \Hyperf\Database\Model\Relations\BelongsTo<Board, $this>
     */
    public function board(): \Hyperf\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    /**
     * Get all posts in this thread.
     *
     * @return \Hyperf\Database\Model\Relations\HasMany<Post, $this>
     */
    public function posts(): \Hyperf\Database\Model\Relations\HasMany
    {
        return $this->hasMany(Post::class, 'thread_id');
    }

    /**
     * Get the OP (original poster) post.
     *
     * The OP is the first post that created the thread.
     * It has is_op = true and its ID equals the thread ID.
     *
     * @return \Hyperf\Database\Model\Relations\HasOne<Post, $this>
     */
    public function op(): \Hyperf\Database\Model\Relations\HasOne
    {
        /** @var \Hyperf\Database\Model\Relations\HasOne<Post, $this> $relation */
        $relation = $this->hasOne(Post::class, 'thread_id')
            ->where('is_op', true);
        return $relation;
    }
}
