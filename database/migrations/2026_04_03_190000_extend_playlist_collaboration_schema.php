<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('collaboration_requires_approval')->default(false)->after('is_collaborative');
            $table->string('collaboration_invite_token', 80)->nullable()->unique()->after('collaboration_requires_approval');
            $table->timestamp('collaboration_invite_expires_at')->nullable()->after('collaboration_invite_token');
        });

        Schema::table('playlist_collaborators', function (Blueprint $table) {
            $table->string('role')->default('editor')->after('user_id');
            $table->foreignId('invited_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->timestamp('joined_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_collaborators', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['role', 'approved_at', 'joined_at']);
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropUnique('playlists_collaboration_invite_token_unique');
            $table->dropColumn([
                'collaboration_requires_approval',
                'collaboration_invite_token',
                'collaboration_invite_expires_at',
            ]);
        });
    }
};
