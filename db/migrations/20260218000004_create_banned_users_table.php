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
 * Migration for banned_users table - ported from OpenYotsuba
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banned_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('board', 10)->default('')->comment('Board slug (empty for global)');
            $table->tinyInteger('global')->default(0)->comment('1=global ban');
            $table->tinyInteger('zonly')->default(0)->comment('1=unappealable');
            $table->string('name', 255)->default('Anonymous');
            $table->string('host', 45)->comment('Banned IP (encrypted)');
            $table->string('reverse', 255)->default('')->comment('Reverse DNS');
            $table->string('xff', 255)->default('')->comment('X-Forwarded-For');
            $table->text('reason')->nullable()->comment('Ban reason (public)');
            $table->timestamp('length')->nullable()->comment('Ban expiration');
            $table->timestamp('now')->useCurrent()->comment('Ban start');
            $table->string('admin', 255)->comment('Staff who issued ban');
            $table->string('md5', 32)->default('')->comment('File MD5 (for file bans)');
            $table->unsignedBigInteger('post_num')->default(0)->comment('Associated post number');
            $table->string('rule', 50)->default('')->comment('Rule violated');
            $table->string('post_time', 20)->default('')->comment('Post timestamp');
            $table->unsignedBigInteger('template_id')->nullable()->comment('Ban template ID');
            $table->string('password', 255)->default('')->comment('Password hash');
            $table->string('pass_id', 255)->default('')->comment('4chan Pass ID');
            $table->text('post_json')->nullable()->comment('Post JSON snapshot');
            $table->string('admin_ip', 45)->default('')->comment('Admin IP');
            $table->tinyInteger('active')->default(1)->comment('1=active');
            $table->tinyInteger('appealable')->default(1)->comment('1=can appeal');
            $table->timestamp('unbannedon')->nullable()->comment('Unban timestamp');
            $table->text('ban_reason')->nullable()->comment('Internal ban reason');
            $table->timestamps();

            // Indexes for efficient ban checks
            $table->index(['host', 'active'], 'idx_host_active');
            $table->index(['pass_id', 'active'], 'idx_pass_active');
            $table->index(['board', 'global', 'active'], 'idx_board_global_active');
            $table->index(['active', 'length'], 'idx_active_length');
            $table->index(['template_id'], 'idx_template');
            $table->index(['md5'], 'idx_md5');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banned_users');
    }
};
