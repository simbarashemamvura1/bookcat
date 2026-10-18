<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Models\Order;
use Illuminate\Support\Str;
use RuntimeException;

/** Local development and tests only. Nothing is charged; tests drive the "webhook" by hand. */
class FakePaymentProvider implements PaymentProvider
{
    // Switches for tests to simulate the provider being down.
    public static bool $failCreate = false;
    public static bool $failRefunds = false;

    public function name(): string
    {
        return 'fake';
    }

    public function createPayment(Order $order): array
    {
        if (self::$failCreate) {
            throw new RuntimeException('Fake provider: create failed.');
        }

        return ['reference' => 'fake_pi_'.Str::lower(Str::random(20)), 'client_secret' => null];
    }

    public function cancelPayment(string $reference): void
    {
    }

    public function refund(string $reference, int $amountCents, string $reason, string $idempotencyKey): void
    {
        if (self::$failRefunds) {
            throw new RuntimeException('Fake provider: refund failed.');
        }
    }
}