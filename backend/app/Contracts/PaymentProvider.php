<?php

namespace App\Contracts;

use App\Models\Order;

interface PaymentProvider
{
    public function name(): string;

    /** @return array{reference: string, client_secret: ?string} */
    public function createPayment(Order $order): array;

    public function cancelPayment(string $reference): void;

    /** The idempotency key makes a retried refund safe: the provider must never refund twice. */
    public function refund(string $reference, int $amountCents, string $reason, string $idempotencyKey): void;
}