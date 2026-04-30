<?php

namespace App\Providers;

use App\Models\AiSession;
use App\Services\AI\AIService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIService::class);
    }

    public function boot(): void
    {
        // Bind {session} route parameter to AiSession (avoids conflict with Laravel's sessions table)
        Route::model('session', AiSession::class);
    }
}
