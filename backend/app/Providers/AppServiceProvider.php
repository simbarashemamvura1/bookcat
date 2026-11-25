<?php

namespace App\Providers;

use App\Contracts\PaymentProvider;
use App\Http\Middleware\CartToken;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentProvider::class, function () {
            $driver = config('bookcat.payments.driver');

            if ($driver === 'fake') {
                if (app()->isProduction()) {
                    throw new RuntimeException('The fake payment provider must never run in production.');
                }

                return new FakePaymentProvider();
            }

            throw new RuntimeException("Unsupported payments driver [{$driver}].");
        });
    }

    public function boot(): void
    {
        // Each limiter checks the buyer's cart AND their address. Someone can drop their cookie to
        // dodge the cart limit, but not the address limit.
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(120)->by('ip:'.$request->ip()));

        RateLimiter::for('cart', fn (Request $request) => [
            Limit::perMinute(40)->by($this->cartKey($request)),
            Limit::perMinute(120)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by($this->cartKey($request)),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        // Higher, because the confirmation page may poll while it waits for the payment.
        RateLimiter::for('orders', fn (Request $request) => [
            Limit::perMinute(90)->by($this->cartKey($request)),
            Limit::perMinute(240)->by('ip:'.$request->ip()),
        ]);
    }

    private function cartKey(Request $request): string
    {
        $cookie = $request->cookie(CartToken::COOKIE);

        // Hashed, so the raw cart token never lands in the cache.
        return is_string($cookie) && $cookie !== ''
            ? 'cart:'.hash('sha256', $cookie)
            : 'ip:'.$request->ip();
    }
}