<?php

namespace App\Providers;

use App\Contracts\PaymentProvider;
use App\Services\Payments\FakePaymentProvider;
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
        //
    }
}
