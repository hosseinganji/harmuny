<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A source can now be a channel or a (super)group the bot is in. */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('kind', 16)->default('channel')->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
