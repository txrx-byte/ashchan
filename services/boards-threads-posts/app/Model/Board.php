<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int    $id
 * @property string $slug
 * @property string $title
 * @property string $subtitle
 * @property string $category
 * @property bool   $nsfw
 * @property int    $max_threads
 * @property int    $bump_limit
 * @property int    $image_limit
 * @property int    $cooldown_seconds
 * @property bool   $text_only
 * @property bool   $require_subject
 * @property string $rules
 * @property bool   $archived
 * @property string $created_at
 * @property string $updated_at
 */
class Board extends Model
{
    protected string $table = 'boards';

    protected array $fillable = [
        'slug', 'title', 'subtitle', 'category', 'nsfw',
        'max_threads', 'bump_limit', 'image_limit',
        'cooldown_seconds', 'text_only', 'require_subject', 'rules',
    ];

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
    ];

    public function threads()
    {
        return $this->hasMany(Thread::class, 'board_id');
    }
}
