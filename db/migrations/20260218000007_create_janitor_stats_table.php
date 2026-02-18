<?php
declare(strict_types=1);

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
