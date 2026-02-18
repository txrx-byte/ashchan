<?php
declare(strict_types=1);

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
