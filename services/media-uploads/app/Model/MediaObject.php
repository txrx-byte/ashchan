<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int         $id
 * @property string      $hash_sha256
 * @property string      $mime_type
 * @property int         $file_size
 * @property int         $width
 * @property int         $height
 * @property string      $storage_key
 * @property string      $thumb_key
 * @property string      $original_filename
 * @property string|null $phash
 * @property bool        $nsfw_flagged
 * @property bool        $banned
 * @property string      $created_at
 */
class MediaObject extends Model
{
    protected ?string $table = 'media_objects';
    public bool $timestamps = false;

    protected array $fillable = [
        'hash_sha256', 'mime_type', 'file_size', 'width', 'height',
        'storage_key', 'thumb_key', 'original_filename', 'phash',
        'nsfw_flagged', 'banned',
    ];

    protected array $casts = [
        'id'           => 'integer',
        'file_size'    => 'integer',
        'width'        => 'integer',
        'height'       => 'integer',
        'nsfw_flagged' => 'boolean',
        'banned'       => 'boolean',
    ];
}
