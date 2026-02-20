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
 * @property int    $board_id
 * @property bool   $sticky
 * @property bool   $locked
 * @property bool   $archived
 * @property int    $reply_count
 * @property int    $image_count
 * @property string $bumped_at
 * @property string $created_at
 * @property string $updated_at
 * 
 * @property-read Board|null $board
 * @property-read \Hyperf\Database\Model\Collection<int, Post> $posts
 * @property-read Post|null $op
 *
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static static|null find(mixed $id, array<string> $columns = ['*'])
 * @method static \Hyperf\Database\Model\Builder<Thread> query()
 */
class Thread extends Model
{
    protected ?string $table = 'threads';
    public bool $incrementing = false; // Manually assigned from Post ID

    /**
     * @var array<string>
     */
    protected array $fillable = [
        'id', 'board_id', 'sticky', 'locked', 'archived',
    ];

    /**
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

    /** @return \Hyperf\Database\Model\Relations\BelongsTo<Board, $this> */
    public function board(): \Hyperf\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    /** @return \Hyperf\Database\Model\Relations\HasMany<Post, $this> */
    public function posts(): \Hyperf\Database\Model\Relations\HasMany
    {
        return $this->hasMany(Post::class, 'thread_id');
    }

    /**
     * The OP (first post).
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
