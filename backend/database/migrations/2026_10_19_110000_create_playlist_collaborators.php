<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Shared playlists: members the owner invites can add, remove and reorder songs. */
    public function up(): void
    {
        Schema::create('playlist_collaborators', function (Blueprint $table) {
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['playlist_id', 'user_id']);
        });

        Schema::table('playlist_track', function (Blueprint $table) {
            $table->foreignId('added_by')->nullable()->after('position')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playlist_track', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
        });
        Schema::dropIfExists('playlist_collaborators');
    }
};
