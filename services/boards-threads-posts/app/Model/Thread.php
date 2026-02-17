<?php
declare(strict_types=1);

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
 */
class Thread extends Model
{
    protected ?string $table = 'threads';

    protected array $fillable = [
        'board_id', 'sticky', 'locked', 'archived',
    ];

    protected array $casts = [
        'id'          => 'integer',
        'board_id'    => 'integer',
        'sticky'      => 'boolean',
        'locked'      => 'boolean',
        'archived'    => 'boolean',
        'reply_count' => 'integer',
        'image_count' => 'integer',
    ];

    public function board()
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function posts()
    {
        return $this->hasMany(Post::class, 'thread_id');
    }

    /** The OP (first post). */
    public function op()
    {
        return $this->hasOne(Post::class, 'thread_id')
            ->where('is_op', true)
            ->oldest('id');
    }
}
