<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('round_id')->nullable()->index()->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['round_id']);
            $table->dropColumn('round_id');
        });
    }
};
