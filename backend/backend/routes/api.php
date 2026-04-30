<?php

use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\PromptController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // ── Models catalogue (public) ──────────────────────────────────────────
    Route::get('models', [ModelController::class, 'index'])->name('models.index');

    // ── Sessions (public) ───────────────────────────────────────────
    Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::post('sessions', [SessionController::class, 'store'])->name('sessions.store');
    Route::get('sessions/{session}', [SessionController::class, 'show'])->whereNumber('session')->name('sessions.show');
    Route::match(['put', 'patch'], 'sessions/{session}', [SessionController::class, 'update'])->whereNumber('session')->name('sessions.update');
    Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->whereNumber('session')->name('sessions.destroy');
    Route::post('sessions/{session}/prompt', [PromptController::class, 'submit'])->whereNumber('session')->name('sessions.prompt');
});
