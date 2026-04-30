<?php

namespace App\Http\Middleware;

use App\Models\AiSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserOwnsSession
{
    /**
     * Access control disabled - app is now public
     * Keeping this middleware for potential future use
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // All sessions are public
        return $next($request);

        /*
        $session = $request->route('session');

        if ($session instanceof AiSession && $session->user_id !== $request->user()?->id) {
            abort(403, 'This session does not belong to you.');
        }

        return $next($request);
        */
    }
}
