<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiAuthentication
{
    public function handle(Request $request, Closure $next, ...$guards): Response
    {
        if ($request->is('api/*') && !$request->bearerToken()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return $next($request);
    }
}