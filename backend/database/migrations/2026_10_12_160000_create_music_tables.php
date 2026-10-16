<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('peer');
            $table->bigInteger('telegram_id')->nullable()->unique();
            $table->string('title')->nullable();
            $table->string('username')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->string('sync_status', 16)->default('idle');
            $table->text('sync_error')->nullable();
            $table->unsignedInteger('sync_progress')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('title');
            $table->string('artist')->nullable()->index();
            $table->string('album')->nullable();
            $table->string('genre')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->string('file_name')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->text('caption')->nullable();
            $table->boolean('has_cover')->default(false);
            $table->string('cache_path')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamp('cache_used_at')->nullable();
            $table->unsignedInteger('play_count')->default(0);
            $table->text('search_text')->nullable();
            $table->boolean('metadata_locked')->default(false);
            $table->timestamp('posted_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['channel_id', 'message_id']);
            $table->index('updated_at');
        });

        Schema::create('lyrics', function (Blueprint $table) {
            $table->id();
            $table->string('lookup_key')->unique();
            $table->longText('synced')->nullable();
            $table->longText('plain')->nullable();
            $table->boolean('instrumental')->default(false);
            $table->string('source', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('cover_path')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('playlist_track', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['playlist_id', 'position']);
        });

        Schema::create('track_likes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'track_id']);
        });

        Schema::create('plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamp('played_at')->index();
            $table->index(['user_id', 'played_at']);
        });
    }

    public function down(): void
    {
        foreach (['plays', 'track_likes', 'playlist_track', 'playlists', 'lyrics', 'tracks', 'channels', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
