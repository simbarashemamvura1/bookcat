<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JsonApi
{
    public function handle(Request $request, Closure $next): Response
    {
        // Errors (404, 429, 500) always come back as JSON, whatever the client asked for.
        $request->headers->set('Accept', 'application/json');

        // Anything that changes data must be sent as JSON. A browser can only send a JSON
        // content type cross-site after a CORS preflight, which our allowlist refuses, so forged
        // form posts from other sites never get through (a second layer on top of SameSite).
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $request->isJson()) {
            return response()->json(['error' => 'json_required'], 415);
        }

        return $next($request);
    }
}