<?php
declare(strict_types=1);

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
 * @property string      $ip_hash
 * @property string|null $country_code
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
 */
class Post extends Model
{
    protected string $table = 'posts';

    protected array $fillable = [
        'thread_id', 'is_op', 'author_name', 'tripcode', 'capcode',
        'email', 'subject', 'content', 'content_html', 'ip_hash',
        'country_code', 'media_id', 'media_url', 'thumb_url',
        'media_filename', 'media_size', 'media_dimensions', 'media_hash',
        'spoiler_image', 'delete_password_hash',
    ];

    protected array $casts = [
        'id'             => 'integer',
        'thread_id'      => 'integer',
        'is_op'          => 'boolean',
        'media_size'     => 'integer',
        'spoiler_image'  => 'boolean',
        'deleted'        => 'boolean',
    ];

    public function thread()
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
