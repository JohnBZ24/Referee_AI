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

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            // Rebuild table to drop NOT NULL on user_id.
            DB::statement('CREATE TABLE ai_sessions__tmp (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NULL,
                title VARCHAR(255) NOT NULL DEFAULT "New Session",
                model_set TEXT NULL,
                referee_model VARCHAR(255) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )');

            DB::statement('INSERT INTO ai_sessions__tmp (id, user_id, title, model_set, referee_model, created_at, updated_at)
                SELECT id, user_id, title, model_set, referee_model, created_at, updated_at FROM ai_sessions');

            DB::statement('DROP TABLE ai_sessions');
            DB::statement('ALTER TABLE ai_sessions__tmp RENAME TO ai_sessions');
            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ai_sessions ALTER COLUMN user_id DROP NOT NULL');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Laravel's foreignId() is BIGINT UNSIGNED by default for MySQL/MariaDB.
            DB::statement('ALTER TABLE ai_sessions MODIFY user_id BIGINT UNSIGNED NULL');

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE ai_sessions ALTER COLUMN user_id BIGINT NULL');

            return;
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ai_sessions', 'user_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement('CREATE TABLE ai_sessions__tmp (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT "New Session",
                model_set TEXT NULL,
                referee_model VARCHAR(255) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )');

            DB::statement('INSERT INTO ai_sessions__tmp (id, user_id, title, model_set, referee_model, created_at, updated_at)
                SELECT id, COALESCE(user_id, 1), title, model_set, referee_model, created_at, updated_at FROM ai_sessions');

            DB::statement('DROP TABLE ai_sessions');
            DB::statement('ALTER TABLE ai_sessions__tmp RENAME TO ai_sessions');
            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ai_sessions ALTER COLUMN user_id SET NOT NULL');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE ai_sessions MODIFY user_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE ai_sessions ALTER COLUMN user_id BIGINT NOT NULL');

            return;
        }
    }
};
