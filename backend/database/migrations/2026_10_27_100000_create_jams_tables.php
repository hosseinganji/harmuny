<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jams: members listening together. Playback is anchored to the server clock: the song `current_uid`
     * was at `position_ms` at `anchor_ms` (epoch milliseconds), so every device can work out where it is now.
     */
    public function up(): void
    {
        Schema::create('jams', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('guests_control')->default(true);
            $table->boolean('autofill')->default(true);
            // [{uid, track_id, by}]: songs already played, the current one and what comes next.
            $table->json('queue');
            $table->string('current_uid', 16)->nullable();
            $table->boolean('playing')->default(false);
            $table->unsignedInteger('position_ms')->default(0);
            $table->unsignedBigInteger('anchor_ms')->default(0);
            $table->json('skip_votes')->nullable();
            $table->json('banned')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('jam_members', function (Blueprint $table) {
            $table->foreignId('jam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Off: the music plays on the others' devices (same room, one speaker); this one adds songs and reacts.
            $table->boolean('listening')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->primary(['jam_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jam_members');
        Schema::dropIfExists('jams');
    }
};
