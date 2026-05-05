<?php

use App\Exceptions\AIProviderException;
use App\Exceptions\InvalidModelException;
use App\Exceptions\RateLimitException;
use App\Http\Middleware\EnsureUserOwnsSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'can_access_session' => EnsureUserOwnsSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = (string) Str::uuid();

            $payload = fn (string $message, string $code, int $status, array $extra = []) => response()->json([
                'error' => array_merge([
                    'message' => $message,
                    'code' => $code,
                    'request_id' => $requestId,
                ], $extra),
            ], $status);

            if ($e instanceof ValidationException) {
                return $payload(
                    'Please check the form and try again.',
                    'VALIDATION_ERROR',
                    $e->status,
                    ['fields' => $e->errors()],
                );
            }

            if ($e instanceof ModelNotFoundException) {
                return $payload('Not found.', 'NOT_FOUND', 404);
            }

            if ($e instanceof RateLimitException) {
                return $payload('Too many requests. Please try again shortly.', 'RATE_LIMITED', 429);
            }

            if ($e instanceof InvalidModelException) {
                return $payload('That model is unavailable. Please pick another model.', 'INVALID_MODEL', 422);
            }

            if ($e instanceof AIProviderException) {
                return $payload('The AI provider failed. Please try again.', 'AI_PROVIDER_ERROR', 502);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $msg = $status === 403 ? 'Forbidden.' : ($status === 401 ? 'Unauthorized.' : 'Request failed.');

                return $payload($msg, 'HTTP_ERROR', $status);
            }

            report($e);

            return $payload('Something went wrong. Please try again.', 'SERVER_ERROR', 500);
        });
    })->create();
