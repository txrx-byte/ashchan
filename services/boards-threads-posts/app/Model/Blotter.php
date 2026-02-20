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
 * @property string $created_at
 * @property string $content
 * @property bool   $is_important
 */
class Blotter extends Model
{
    protected ?string $table = 'blotter';

    public bool $timestamps = false;

    /** @var array<string> */
    protected array $fillable = ['content', 'is_important'];

    /** @var array<string, string> */
    protected array $casts = [
        'id'           => 'integer',
        'is_important' => 'boolean',
    ];
}
