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
 * Migration for ban_requests table - ported from OpenYotsuba
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ban_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('board', 10)->comment('Board slug');
            $table->unsignedBigInteger('post_no')->comment('Post number');
            $table->string('janitor', 255)->comment('Janitor username');
            $table->unsignedBigInteger('ban_template')->comment('Template ID');
            $table->text('post_json')->comment('Post JSON snapshot');
            $table->text('reason')->nullable()->comment('Ban reason');
            $table->string('length', 20)->default('')->comment('Requested ban length');
            $table->string('image_hash', 255)->default('')->comment('Image hash');
            $table->timestamps();

            // Indexes
            $table->index(['board'], 'idx_board');
            $table->index(['janitor'], 'idx_janitor');
            $table->index(['ban_template'], 'idx_template');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ban_requests');
    }
};
