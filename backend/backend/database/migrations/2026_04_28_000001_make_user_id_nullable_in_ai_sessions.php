<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            Schema::disableForeignKeyConstraints();

            Schema::rename('ai_sessions', 'ai_sessions_old');

            Schema::create('ai_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('title')->default('New Session');
                $table->json('model_set')->nullable();
                $table->string('referee_model')->nullable();
                $table->timestamps();
            });

            DB::table('ai_sessions')->insertUsing(
                ['id', 'user_id', 'title', 'model_set', 'referee_model', 'created_at', 'updated_at'],
                DB::table('ai_sessions_old')->select(['id', 'user_id', 'title', 'model_set', 'referee_model', 'created_at', 'updated_at']),
            );

            Schema::drop('ai_sessions_old');

            Schema::enableForeignKeyConstraints();

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
            Schema::disableForeignKeyConstraints();

            Schema::rename('ai_sessions', 'ai_sessions_old');

            Schema::create('ai_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title')->default('New Session');
                $table->json('model_set')->nullable();
                $table->string('referee_model')->nullable();
                $table->timestamps();
            });

            DB::table('ai_sessions')->insertUsing(
                ['id', 'user_id', 'title', 'model_set', 'referee_model', 'created_at', 'updated_at'],
                DB::table('ai_sessions_old')->select(['id', 'user_id', 'title', 'model_set', 'referee_model', 'created_at', 'updated_at']),
            );

            Schema::drop('ai_sessions_old');

            Schema::enableForeignKeyConstraints();

            return;
        }

        DB::statement('ALTER TABLE ai_sessions ALTER COLUMN user_id SET NOT NULL');
    }
};
