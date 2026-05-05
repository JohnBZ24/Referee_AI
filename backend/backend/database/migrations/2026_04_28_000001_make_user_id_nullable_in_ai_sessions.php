<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_sessions', 'user_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        // SQLite rebuilds can break dependent foreign keys (messages.session_id -> ai_sessions).
        // For this app we only rely on SQLite for tests, and factories always set user_id.
        if ($driver === 'sqlite') {
            return;
        }

        // Avoid doctrine/dbal dependency for a simple nullability change.
        DB::statement('ALTER TABLE ai_sessions ALTER COLUMN user_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ai_sessions', 'user_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE ai_sessions ALTER COLUMN user_id SET NOT NULL');
    }
};
