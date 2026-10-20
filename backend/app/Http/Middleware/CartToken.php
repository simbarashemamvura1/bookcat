<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CartToken
{
    public const COOKIE = 'bookcat_cart';

    /**
     * 'read'   never creates a cookie (reads stay cacheable, and nothing is minted for no reason).
     * 'create' issues a new token when the browser has none.
     * The token is random and lives in an httpOnly cookie. It is never put in a URL or a response body.
     */
    public function handle(Request $request, Closure $next, string $mode = 'read'): Response
    {
        $token = $request->cookie(self::COOKIE);
        $valid = is_string($token) && preg_match('/^[A-Za-z0-9]{32,64}$/', $token) === 1;
        $issued = false;

        if (! $valid) {
            $token = null;

            if ($mode === 'create') {
                // Ceiling on brand-new carts per address, so a script cannot open carts endlessly.
                // The numbers are a first guess: 30 new carts per 10 minutes.
                $key = 'new-cart:'.$request->ip();

                if (RateLimiter::tooManyAttempts($key, 30)) {
                    return response()->json(['error' => 'too_many_carts'], 429);
                }

                RateLimiter::hit($key, 600);

                $token = Str::random(40);
                $issued = true;
            }
        }

        $request->attributes->set('cart_token', $token);

        $response = $next($request);

        if ($issued) {
            $response->headers->setCookie(cookie(
                self::COOKIE,
                $token,
                60 * 24 * 7,                                  // 7 days: long enough to reopen an order page
                '/',
                null,
                $request->isSecure() || app()->isProduction(), // Secure everywhere except plain-http local dev
                true,                                          // httpOnly: scripts on the page can never read it
                false,
                'lax',
            ));
        }

        return $response;
    }
}