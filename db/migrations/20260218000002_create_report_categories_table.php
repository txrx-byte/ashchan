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
 * Migration for report_categories table - ported from OpenYotsuba
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('board', 20)->default('')->comment('Board slug or empty for global (_ws_, _nws_)');
            $table->string('title', 255)->comment('Category title');
            $table->decimal('weight', 10, 2)->default(1.00)->comment('Weight/severity multiplier');
            $table->string('exclude_boards', 255)->default('')->comment('Comma-separated excluded boards');
            $table->unsignedInteger('filtered')->default(0)->comment('Filter threshold (0=disabled)');
            $table->tinyInteger('op_only')->default(0)->comment('1=OP posts only');
            $table->tinyInteger('reply_only')->default(0)->comment('1=Replies only');
            $table->tinyInteger('image_only')->default(0)->comment('1=Images only');
            $table->timestamps();

            // Indexes
            $table->index(['board'], 'idx_board');
            $table->index(['weight'], 'idx_weight');
            
            // Unique constraint on board+title
            $table->unique(['board', 'title'], 'uniq_board_title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_categories');
    }
};
