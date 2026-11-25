<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('excel_api.api_key');

        if ($expected === '') {
            return response()->json(['message' => 'API_KEY is not configured on the server.'], 500);
        }

        if (! hash_equals($expected, (string) $request->header('X-API-Key'))) {
            return response()->json(['message' => 'Invalid or missing API key.'], 401);
        }

        return $next($request);
    }
}
