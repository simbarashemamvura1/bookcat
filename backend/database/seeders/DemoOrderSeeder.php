<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\Event;
use App\Models\EventSeat;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\FeeCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoOrderSeeder extends Seeder
{
    public function run(): void
    {
        $event = Event::where('title', 'Demo Concert')->firstOrFail();

        if (Order::where('event_id', $event->id)->exists()) {
            return;
        }

        $terms = $event->financialTerms;
        $organizerAccount = $event->organizer->account;
        $seatKeys = ['ORCH-A-1', 'ORCH-A-2', 'BALC-A-1'];

        // Everything runs in one transaction: a failure leaves nothing half-seeded,
        // so the seeder can simply be run again.
        DB::transaction(function () use ($event, $terms, $organizerAccount, $seatKeys) {
            $seats = $event->seats()->whereIn('seat_key', $seatKeys)->get()->keyBy('seat_key');

            if ($seats->count() !== count($seatKeys)) {
                throw new RuntimeException('Demo seats not found. Run DemoEventSeeder first.');
            }

            // CAD 10 off EACH ticket, reusable
            $coupon = new Coupon([
                'code' => 'save10',
                'discount_type' => Coupon::TYPE_FLAT,
                'discount_value' => 1000,
                'usage_type' => Coupon::USAGE_MULTIPLE,
            ]);
            $coupon->forceFill(['event_id' => $event->id, 'created_by' => $organizerAccount->id])->save();

            if (! $coupon->claim()) {
                throw new RuntimeException('Coupon could not be claimed.');
            }

            $order = new Order(['buyer_name' => 'Sam Buyer', 'buyer_email' => 'sam@example.test']);
            $order->forceFill([
                'type' => Order::TYPE_PURCHASE,
                'event_id' => $event->id,
                'currency' => $event->currency,
                'coupon_id' => $coupon->id,
                'status' => Order::STATUS_PAID,
                'paid_at' => now(),
            ])->save();

            foreach ($seatKeys as $i => $key) {
                $seat = $seats[$key];
                $list = (int) $seat->price_cents;
                $discount = FeeCalculator::discountFor($coupon, $list);
                $paid = $list - $discount;
                $fees = FeeCalculator::feesFor($terms, Ticket::KIND_PAID, $paid);

                $ticket = new Ticket([
                    'attendee_name' => $i === 0 ? 'Sam Buyer' : 'Guest '.$i,
                    'attendee_email' => $i === 0 ? 'sam@example.test' : null,
                ]);
                $ticket->forceFill([
                    'order_id' => $order->id,
                    'event_id' => $event->id,
                    'event_seat_id' => $seat->id,
                    'kind' => Ticket::KIND_PAID,
                    'is_buyer_seat' => $i === 0,
                    'list_price_cents' => $list,
                    'discount_cents' => $discount,
                    'price_paid_cents' => $paid,
                    ...$fees,
                ])->save();

                $seat->forceFill(['status' => EventSeat::STATUS_SOLD])->save();
            }

            $order->recalculateTotals();
        });
    }
}