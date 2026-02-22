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
 * GDPR/CCPA data rights request (export or deletion).
 *
 * Tracks the lifecycle of user-initiated data requests. Status transitions:
 *   pending → processing → completed
 *   pending → denied
 *
 * @property int         $id
 * @property int         $user_id       The requesting user's ID
 * @property string      $status        One of: pending, processing, completed, denied
 * @property string      $request_type  One of: data_export, data_deletion
 * @property string      $requested_at  When the request was submitted
 * @property string|null $completed_at  When the request was fulfilled (null while pending)
 * @method static DeletionRequest|null find(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<DeletionRequest> query()
 * @method static DeletionRequest create(array<string, mixed> $attributes)
 */
final class DeletionRequest extends Model
{
    protected ?string $table = 'deletion_requests';
    public bool $timestamps = false;

    /** @var string[] Columns that may be mass-assigned. */
    protected array $fillable = [
        'user_id', 'status', 'request_type', 'requested_at',
    ];

    /** @var array<string, string> Column type casts. */
    protected array $casts = [
        'id'      => 'integer',
        'user_id' => 'integer',
    ];
}
