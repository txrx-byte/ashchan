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


use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * Migration for janitor_stats table - ported from OpenYotsuba
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('janitor_stats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->comment('Janitor user ID');
            $table->tinyInteger('action_type')->comment('0=denied, 1=accepted');
            $table->string('board', 10)->comment('Board slug');
            $table->unsignedBigInteger('post_id')->comment('Post number');
            $table->unsignedInteger('requested_tpl')->comment('Requested template ID');
            $table->unsignedInteger('accepted_tpl')->comment('Accepted template ID (0 if denied)');
            $table->unsignedBigInteger('created_by_id')->comment('Moderator who approved/denied');
            $table->timestamp('created_on')->useCurrent();

            // Indexes for stats queries
            $table->index(['user_id', 'created_on'], 'idx_user_created');
            $table->index(['board'], 'idx_board');
            $table->index(['action_type'], 'idx_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('janitor_stats');
    }
};
