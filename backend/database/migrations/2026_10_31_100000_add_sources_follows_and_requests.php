<?php

use App\Models\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each song came from (a channel the app syncs, an admin's upload, or a public channel the daily
     * discovery reads), who brought it, and a key that recognises the same song arriving twice.
     * Plus members following artists, and song requests.
     */
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->string('source', 16)->default(Track::SOURCE_TELEGRAM)->after('channel_id')->index();
            $table->string('source_url')->nullable()->after('source');
            $table->string('external_id', 191)->nullable()->unique()->after('source_url');
            $table->foreignId('added_by')->nullable()->after('external_id')->constrained('users')->nullOnDelete();
            $table->string('match_key', 191)->nullable()->after('search_text')->index();
        });

        // Existing songs: their key, and a link to the post they came from.
        $channels = DB::table('channels')->get(['id', 'peer', 'username', 'telegram_id'])->keyBy('id');
        Track::withTrashed()->chunkById(500, function ($tracks) use ($channels) {
            foreach ($tracks as $track) {
                $channel = $channels[$track->channel_id] ?? null;
                DB::table('tracks')->where('id', $track->id)->update([
                    'match_key' => Track::matchKey($track->artist, $track->title),
                    'source_url' => $channel && $track->message_id ? Track::postUrl($channel->username ?: $channel->peer, $channel->telegram_id, (int) $track->message_id) : null,
                ]);
            }
        });

        Schema::create('artist_follows', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('artist', 191);
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'artist']);
            $table->index('artist');
        });

        Schema::create('song_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_requests');
        Schema::dropIfExists('artist_follows');
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
            $table->dropUnique(['external_id']);
            $table->dropColumn(['source', 'source_url', 'external_id', 'match_key']);
        });
    }
};
