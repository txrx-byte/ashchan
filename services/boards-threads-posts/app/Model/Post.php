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
 * Imageboard post model.
 *
 * Represents a single post (either OP or reply) within a thread.
 * Posts contain the actual content (text, images) that users create.
 *
 * Database table: `posts`
 *
 * Post Types:
 * - OP (is_op = true): First post that creates a thread
 * - Reply (is_op = false): Subsequent posts in a thread
 *
 * PII Handling:
 * - ip_address: Encrypted with XChaCha20-Poly1305, nullified after retention period
 * - email: Nullified after retention period
 * - delete_password_hash: Bcrypt hash for user deletion
 * - edit_password_hash: Bcrypt hash for liveposting reclaim
 *
 * Media Handling:
 * - media_url: Full-size image/media URL
 * - thumb_url: Thumbnail URL
 * - media_filename: Original filename
 * - media_size: File size in bytes
 * - media_dimensions: "WxH" string (e.g., "800x600")
 * - media_hash: Base64 MD5 hash for deduplication
 * - spoiler_image: Whether image is spoilered
 *
 * @property int $id Auto-incrementing primary key (from posts_id_seq)
 * @property int $thread_id Foreign key to threads table
 * @property bool $is_op Whether this is the OP (first post) of the thread
 * @property string|null $author_name Display name (may include tripcode)
 * @property string|null $tripcode Generated tripcode (!ABC123)
 * @property string|null $capcode Staff capcode (mod, admin, etc.)
 * @property string|null $email Email field (often "sage")
 * @property string|null $subject Post subject (OP only typically)
 * @property string $content Raw post content (markup text)
 * @property string|null $content_html Parsed HTML for display
 * @property string|null $ip_address Encrypted IP address (enc:base64 or NULL after retention)
 * @property string|null $country_code ISO country code (e.g., "US")
 * @property string|null $country_name Country display name
 * @property string|null $poster_id Deterministic hash (IP + thread + day)
 * @property int|null $board_post_no Per-board post number (legacy)
 * @property string|null $media_id Media reference ID
 * @property string|null $media_url Full-size media URL
 * @property string|null $thumb_url Thumbnail URL
 * @property string|null $media_filename Original filename
 * @property int|null $media_size File size in bytes
 * @property string|null $media_dimensions Dimensions string ("WxH")
 * @property string|null $media_hash Base64 MD5 hash
 * @property bool $spoiler_image Whether image is spoilered
 * @property string|null $delete_password_hash Bcrypt hash for deletion
 * @property bool $deleted Whether post is deleted (soft delete flag)
 * @property string|null $deleted_at Timestamp when post was deleted
 * @property bool $is_editing Whether post is in liveposting state
 * @property string|null $edit_password_hash Bcrypt hash for reclaim
 * @property string|null $edit_expires_at Liveposting session expiry
 * @property string $created_at Timestamp when post was created
 * @property string $updated_at Timestamp when post was last updated
 *
 * @property-read string $media_size_human Formatted media size (e.g., "1.2 MB")
 * @property-read string $content_preview Content preview for catalog (160 chars)
 * @property-read \Hyperf\Database\Model\Relations\BelongsTo<Thread, $this> $thread
 *
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static static|null find(mixed $id, array<string> $columns = ['*'])
 * @method static \Hyperf\Database\Model\Builder<Post> query()
 *
 * @see \App\Controller\ThreadController For post API
 * @see \App\Service\BoardService For post business logic
 * @see Thread Parent thread
 * @see OpenPostBody Liveposting body storage
 */
class Post extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'posts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected array $fillable = [
        'id', 'thread_id', 'is_op', 'author_name', 'tripcode', 'capcode',
        'email', 'subject', 'content', 'content_html', 'ip_address',
        'country_code', 'country_name', 'poster_id', 'board_post_no',
        'media_id', 'media_url', 'thumb_url',
        'media_filename', 'media_size', 'media_dimensions', 'media_hash',
        'spoiler_image', 'delete_password_hash',
        'deleted', 'deleted_at',
        'is_editing', 'edit_password_hash', 'edit_expires_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'id'             => 'integer',
        'thread_id'      => 'integer',
        'is_op'          => 'boolean',
        'media_size'     => 'integer',
        'spoiler_image'  => 'boolean',
        'deleted'        => 'boolean',
        'board_post_no'  => 'integer',
        'is_editing'     => 'boolean',
    ];

    /**
     * Get the parent thread.
     *
     * @return \Hyperf\Database\Model\Relations\BelongsTo<Thread, $this>
     */
    public function thread(): \Hyperf\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    /**
     * Format media size for human-readable display.
     *
     * Converts bytes to appropriate unit (B, KB, MB).
     *
     * @return string Formatted size (e.g., "1.2 MB", "256 KB", "1024 B")
     */
    public function getMediaSizeHumanAttribute(): string
    {
        $bytes = $this->media_size ?? 0;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    /**
     * Create a content preview for catalog display.
     *
     * Strips HTML tags and truncates to 160 characters with ellipsis.
     *
     * @return string Content preview (max 160 characters)
     */
    public function getContentPreviewAttribute(): string
    {
        $text = strip_tags($this->content_html ?? $this->content ?? '');
        return mb_strlen($text) > 160 ? mb_substr($text, 0, 160) . '…' : $text;
    }
}
