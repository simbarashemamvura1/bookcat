<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\EventFinancialTerms;
use App\Models\Ticket;

class FeeCalculator
{
    /** Integer basis-point math, rounded half up: 2.5% of 4000 cents is 100. */
    public static function percentOf(int $cents, int $bps): int
    {
        return intdiv($cents * $bps + 5000, 10000);
    }

    /** Discount for ONE ticket (coupons apply per ticket), never more than its price. */
    public static function discountFor(?Coupon $coupon, int $listPriceCents): int
    {
        if ($coupon === null) {
            return 0;
        }

        $discount = $coupon->discount_type === Coupon::TYPE_PERCENT
            ? self::percentOf($listPriceCents, (int) $coupon->discount_value)
            : (int) $coupon->discount_value;

        return min($discount, $listPriceCents);
    }

    /**
     * Fees Bookcat deducts from the organizer for one ticket, computed on the price
     * actually paid. Fully free events carry zero rates, so every fee is 0 there.
     *
     * @return array{bookcat_fee_cents:int, processing_fee_cents:int}
     */
    public static function feesFor(EventFinancialTerms $terms, string $kind, int $pricePaidCents): array
    {
        $flat = (int) $terms->ticket_fee_flat_cents;

        // Comp tickets: only the flat portion, and nothing was charged to process.
        if ($kind === Ticket::KIND_COMP) {
            return ['bookcat_fee_cents' => $flat, 'processing_fee_cents' => 0];
        }

        return [
            'bookcat_fee_cents' => $flat + self::percentOf($pricePaidCents, (int) $terms->ticket_fee_bps),
            'processing_fee_cents' => self::percentOf($pricePaidCents, (int) $terms->processing_fee_bps),
        ];
    }
}