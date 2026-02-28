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
 * Imageboard board configuration model.
 *
 * Represents a single imageboard (e.g., /b/, /g/, /v/) with all its
 * configuration settings including posting limits, features, and flags.
 *
 * Database table: `boards`
 *
 * Board Lifecycle:
 * 1. Created by admin via BoardController::store()
 * 2. Configured with posting limits and feature flags
 * 3. Threads and posts are created within the board
 * 4. May be archived (read-only) or deleted (cascade deletes all content)
 *
 * @property int $id Auto-incrementing primary key
 * @property string $slug URL-friendly identifier (e.g., "b", "g", "v")
 * @property string $title Board display title (e.g., "Random", "Technology")
 * @property string $subtitle Board subtitle/tagline
 * @property string $name Internal board name (auto-synced with title)
 * @property string $category Board category for grouping (e.g., "Japanese Culture")
 * @property bool $nsfw Whether board contains not-safe-for-work content
 * @property int $max_threads Maximum active threads before pruning
 * @property int $bump_limit Maximum replies before thread stops bumping
 * @property int $image_limit Maximum images before thread stops accepting images
 * @property int $cooldown_seconds Posting cooldown between posts
 * @property bool $text_only Whether board is text-only (no images required)
 * @property bool $require_subject Whether subject is required for new threads
 * @property string $rules Board rules (HTML markup)
 * @property bool $archived Whether board is archived (read-only)
 * @property bool $staff_only Whether board is restricted to staff
 * @property bool $user_ids Whether poster IDs are shown (deterministic hash per IP)
 * @property bool $country_flags Whether country flags are shown
 * @property int $next_post_no Next per-board post number (legacy compatibility)
 * @property string $created_at Timestamp when board was created
 * @property string $updated_at Timestamp when board was last updated
 *
 * @property-read \Hyperf\Database\Model\Relations\HasMany<Thread, $this> $threads
 *
 * @see \App\Controller\BoardController For board management API
 * @see \App\Service\BoardService For board business logic
 * @see Thread Child threads on this board
 */
class Board extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'boards';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected array $fillable = [
        'slug', 'title', 'subtitle', 'category', 'nsfw',
        'max_threads', 'bump_limit', 'image_limit',
        'cooldown_seconds', 'text_only', 'require_subject', 'rules',
        'archived', 'staff_only', 'user_ids', 'country_flags', 'next_post_no',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'id'               => 'integer',
        'nsfw'             => 'boolean',
        'max_threads'      => 'integer',
        'bump_limit'       => 'integer',
        'image_limit'      => 'integer',
        'cooldown_seconds' => 'integer',
        'text_only'        => 'boolean',
        'require_subject'  => 'boolean',
        'archived'         => 'boolean',
        'staff_only'       => 'boolean',
        'user_ids'         => 'boolean',
        'country_flags'    => 'boolean',
        'next_post_no'     => 'integer',
    ];

    /**
     * Get all threads on this board.
     *
     * @return \Hyperf\Database\Model\Relations\HasMany<Thread, $this>
     */
    public function threads(): \Hyperf\Database\Model\Relations\HasMany
    {
        return $this->hasMany(Thread::class, 'board_id');
    }
}
