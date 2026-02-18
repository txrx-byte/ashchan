<?php
declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * Migration for ban_templates table - ported from OpenYotsuba
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ban_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('rule', 50)->comment('Rule ID (e.g., global1, global2, etc.)');
            $table->string('name', 255)->comment('Template name');
            $table->enum('ban_type', ['local', 'global', 'zonly'])->default('local');
            $table->integer('ban_days')->default(0)->comment('Days (0=warn, -1=permanent)');
            $table->string('banlen', 50)->default('')->comment('Ban length string (e.g., indefinite)');
            $table->tinyInteger('can_warn')->default(1)->comment('1=can issue warning');
            $table->tinyInteger('publicban')->default(0)->comment('1=public ban message');
            $table->tinyInteger('is_public')->default(0)->comment('1=display on ban page');
            $table->text('public_reason')->nullable()->comment('Public reason shown to user');
            $table->text('private_reason')->nullable()->comment('Internal reason (staff only)');
            $table->string('action', 50)->default('')->comment('Action type (quarantine, revokepass_illegal, etc)');
            $table->string('save_type', 20)->default('')->comment('everything|json_only|empty');
            $table->tinyInteger('blacklist_image')->default(0)->comment('1=add to image blacklist');
            $table->tinyInteger('reject_image')->default(0)->comment('1=reject image');
            $table->string('access', 20)->default('janitor')->comment('Required access level');
            $table->string('boards', 255)->default('')->comment('Comma-separated board list');
            $table->string('exclude', 50)->default('')->comment('Exclude pattern (__nofile__, __nws__)');
            $table->tinyInteger('appealable')->default(1)->comment('1=can appeal');
            $table->tinyInteger('active')->default(1)->comment('1=active template');
            $table->timestamps();

            // Indexes
            $table->index(['rule'], 'idx_rule');
            $table->index(['ban_type'], 'idx_ban_type');
            $table->index(['access'], 'idx_access');
            $table->index(['active'], 'idx_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ban_templates');
    }
};
