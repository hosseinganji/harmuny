<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What each member is listening to right now, for the online list. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('now_playing_track_id')->nullable()->after('last_seen_at')->constrained('tracks')->nullOnDelete();
            $table->timestamp('now_playing_at')->nullable()->after('now_playing_track_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('now_playing_track_id');
            $table->dropColumn('now_playing_at');
        });
    }
};
