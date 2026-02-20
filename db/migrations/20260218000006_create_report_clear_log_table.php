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
 * Migration for report_clear_log table - ported from OpenYotsuba
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_clear_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ip', 45)->comment('Reporter IP (encrypted)');
            $table->string('pwd', 255)->nullable()->comment('Reporter password hash');
            $table->string('pass_id', 255)->nullable()->comment('4chan Pass ID');
            $table->unsignedInteger('category')->comment('Report category ID');
            $table->decimal('weight', 10, 2)->comment('Report weight');
            $table->timestamp('created_at')->useCurrent();

            // Indexes for abuse detection
            $table->index(['ip', 'created_at'], 'idx_ip_created');
            $table->index(['pwd', 'created_at'], 'idx_pwd_created');
            $table->index(['pass_id', 'created_at'], 'idx_pass_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_clear_log');
    }
};
