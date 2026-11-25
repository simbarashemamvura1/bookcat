<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;

final class CheckoutResult
{
    /**
     * @param string $reason  ok | expired | invalid_attendees | coupon_invalid | coupon_unavailable
     *                        | event_not_purchasable | seats_not_sellable | cart_changed | seat_lost
     *                        | payment_unavailable | payment_failed | unknown_payment | refunded
     *                        | refund_pending | cart_locked | seats_unavailable | error
     * @param array  $problems  which fields or seats were wrong (invalid_attendees) or in conflict
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = 'ok',
        public readonly ?Order $order = null,
        public readonly ?Payment $payment = null,
        public readonly ?string $clientSecret = null,
        public readonly array $problems = [],
    ) {
    }

    public static function fail(string $reason, array $problems = []): self
    {
        return new self(false, $reason, null, null, null, array_values($problems));
    }
}