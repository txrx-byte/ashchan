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
 * Site announcement/blotter entry model.
 *
 * Represents a single announcement displayed in the site blotter.
 * Blotter entries are shown on the front page and in the API to inform
 * users of site updates, maintenance, or important notices.
 *
 * Database table: `blotter`
 *
 * @property int $id Auto-incrementing primary key
 * @property string $created_at Timestamp when announcement was posted
 * @property string $content Announcement text (supports basic markup)
 * @property bool $is_important Whether this is an important announcement
 *
 * @see \App\Controller\BoardController::blotter() For API endpoint
 * @see \App\Service\BoardService::getBlotter() For retrieval logic
 */
class Blotter extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'blotter';

    /**
     * Indicates if the model should be timestamped.
     *
     * Blotter entries only have created_at (no updated_at).
     *
     * @var bool
     */
    public bool $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected array $fillable = ['content', 'is_important'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'id'           => 'integer',
        'is_important' => 'boolean',
    ];
}
