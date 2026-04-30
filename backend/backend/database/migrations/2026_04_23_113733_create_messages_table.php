<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_sessions')->cascadeOnDelete();
            $table->enum('role', ['user', 'panelist', 'referee']);
            $table->string('model_name')->nullable();
            $table->unsignedTinyInteger('panel_position')->nullable();
            $table->longText('content')->default('');
            $table->enum('status', ['pending', 'streaming', 'complete', 'error'])->default('pending');
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
