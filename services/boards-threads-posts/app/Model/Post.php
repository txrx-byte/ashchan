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
 * @property int         $id
 * @property int         $thread_id
 * @property bool        $is_op
 * @property string|null $author_name
 * @property string|null $tripcode
 * @property string|null $capcode
 * @property string|null $email
 * @property string|null $subject
 * @property string      $content
 * @property string|null $content_html
 * @property string|null $ip_address     Encrypted IP address (enc:base64 or NULL after retention)
 * @property string|null $country_code
 * @property string|null $country_name
 * @property string|null $poster_id
 * @property int|null    $board_post_no
 * @property string|null $media_id
 * @property string|null $media_url
 * @property string|null $thumb_url
 * @property string|null $media_filename
 * @property int|null    $media_size
 * @property string|null $media_dimensions
 * @property string|null $media_hash
 * @property bool        $spoiler_image
 * @property string|null $delete_password_hash
 * @property bool        $deleted
 * @property string|null $deleted_at
 * @property string      $created_at
 * @property string      $updated_at
 * 
 * @property-read string $media_size_human
 * @property-read string $content_preview
 * @property-read Thread|null $thread
 *
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static static|null find(mixed $id, array<string> $columns = ['*'])
 * @method static \Hyperf\Database\Model\Builder<Post> query()
 */
class Post extends Model
{
    protected ?string $table = 'posts';

    /**
     * @var array<string>
     */
    protected array $fillable = [
        'id', 'thread_id', 'is_op', 'author_name', 'tripcode', 'capcode',
        'email', 'subject', 'content', 'content_html', 'ip_address',
        'country_code', 'country_name', 'poster_id', 'board_post_no',
        'media_id', 'media_url', 'thumb_url',
        'media_filename', 'media_size', 'media_dimensions', 'media_hash',
        'spoiler_image', 'delete_password_hash',
    ];

    /**
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
    ];

    /** @return \Hyperf\Database\Model\Relations\BelongsTo<Thread, $this> */
    public function thread(): \Hyperf\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    /** Format media size for display. */
    public function getMediaSizeHumanAttribute(): string
    {
        $bytes = $this->media_size ?? 0;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    /** Create a content preview (for catalog). */
    public function getContentPreviewAttribute(): string
    {
        $text = strip_tags($this->content_html ?? $this->content ?? '');
        return mb_strlen($text) > 160 ? mb_substr($text, 0, 160) . '…' : $text;
    }
}
