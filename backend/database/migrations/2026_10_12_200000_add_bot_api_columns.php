<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            // Bot API file ids (stable per bot), used when syncing through a bot instead of a user account.
            $table->string('file_id')->nullable()->after('message_id');
            $table->string('thumb_file_id')->nullable()->after('file_id');
        });

        Schema::table('channels', function (Blueprint $table) {
            // Audio posts the bot could not take (files over the Bot API's 20 MB download limit).
            $table->unsignedInteger('skipped_count')->default(0)->after('sync_progress');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn(['file_id', 'thumb_file_id']));
        Schema::table('channels', fn (Blueprint $table) => $table->dropColumn('skipped_count'));
    }
};
