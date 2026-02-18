<?php
declare(strict_types=1);

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
