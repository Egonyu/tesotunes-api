<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('setting_audits')) {
            return;
        }

        Schema::create('setting_audits', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 191);
            $table->string('group', 50);
            $table->string('audit_category', 60)->nullable();
            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->unsignedInteger('old_version')->nullable();
            $table->unsignedInteger('new_version');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_ip', 45)->nullable();
            $table->string('actor_role', 50)->nullable();
            $table->string('reason', 255)->nullable();
            $table->boolean('was_secret')->default(false);
            $table->foreignId('reverted_from')->nullable()->constrained('setting_audits')->nullOnDelete();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['setting_key', 'changed_at'], 'setting_audits_key_changed_idx');
            $table->index(['actor_user_id', 'changed_at'], 'setting_audits_actor_changed_idx');
            $table->index(['group', 'changed_at'], 'setting_audits_group_changed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_audits');
    }
};
