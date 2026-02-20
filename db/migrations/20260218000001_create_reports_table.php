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
 * Migration for reports table - ported from OpenYotsuba
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ip', 45)->comment('Reporter IP (encrypted)');
            $table->string('pwd', 255)->nullable()->comment('Reporter password hash');
            $table->string('pass_id', 255)->nullable()->comment('4chan Pass ID');
            $table->string('req_sig', 255)->nullable()->comment('Request signature for spam filtering');
            $table->string('board', 10)->comment('Board slug');
            $table->unsignedBigInteger('no')->comment('Post number');
            $table->unsignedBigInteger('resto')->default(0)->comment('Thread number (0 if OP)');
            $table->tinyInteger('cat')->default(1)->comment('Category type (1=rule, 2=illegal)');
            $table->decimal('weight', 10, 2)->default(1.00)->comment('Report weight/severity');
            $table->unsignedInteger('report_category')->comment('Specific category ID');
            $table->string('post_ip', 45)->comment('Post author IP');
            $table->text('post_json')->comment('JSON snapshot of the post');
            $table->tinyInteger('cleared')->default(0)->comment('0=pending, 1=cleared');
            $table->string('cleared_by', 255)->default('')->comment('Staff who cleared');
            $table->tinyInteger('ws')->default(0)->comment('Worksafe flag (1=ws, 0=nws)');
            $table->timestamp('ts')->useCurrent()->comment('Report timestamp');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['board', 'cleared'], 'idx_board_cleared');
            $table->index(['no', 'board'], 'idx_post_board');
            $table->index(['cleared', 'ts'], 'idx_cleared_ts');
            $table->index(['ip', 'cleared'], 'idx_ip_cleared');
            $table->index(['pass_id', 'cleared'], 'idx_pass_cleared');
            $table->index(['report_category'], 'idx_category');
            
            // Full-text style indexing for search (using trigram in production)
            $table->index(['board', 'no', 'resto'], 'idx_board_post');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
