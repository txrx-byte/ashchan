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
 * @method static MediaObject|null find(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<MediaObject> query()
 * @method static MediaObject create(array<string, mixed> $attributes)
 */
class MediaObject extends Model
{
    protected ?string $table = 'media_objects';
    public bool $timestamps = false;

    /** @var array<int, string> */
    protected array $fillable = [
        'hash_sha256', 'mime_type', 'file_size', 'width', 'height',
        'storage_key', 'thumb_key', 'original_filename', 'phash',
        'nsfw_flagged', 'banned',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'id'           => 'integer',
        'file_size'    => 'integer',
        'width'        => 'integer',
        'height'       => 'integer',
        'nsfw_flagged' => 'boolean',
        'banned'       => 'boolean',
    ];
}
