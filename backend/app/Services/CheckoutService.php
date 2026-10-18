<?php

namespace App\Services;

use App\Contracts\PaymentProvider;
use App\Models\AttendeeAccount;
use App\Models\Coupon;
use App\Models\Event;
use App\Models\EventSeat;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CheckoutService
{
    public function __construct(
        private readonly SeatHoldService $holds,
        private readonly PaymentProvider $provider,
    ) {
    }

    /**
     * The buyer pressed Pay. Locks the cart, snapshots prices onto pending tickets, claims the
     * coupon and creates the payment. Nothing is sold until the payment succeeds.
     *
     * Idempotent per cart: while a pending order exists, the same order comes back. To change
     * seats, details or coupon, call abort() first.
     *
     * @param array<string, array{name: string, email: ?string}> $attendees  keyed by seat key
     */
    public function start(
        Event $event,
        string $token,
        string $buyerEmail,
        array $attendees,
        string $ownSeatKey,
        ?string $couponCode = null,
        ?AttendeeAccount $account = null,
    ): CheckoutResult {
        $terms = $event->financialTerms ?? throw new RuntimeException('Event has no financial terms.');

        $email = strtolower(trim($buyerEmail));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return CheckoutResult::fail('invalid_attendees', ['buyer_email']);
        }

        // The seat list always comes from the cart, never from the client.
        $cart = $this->holds->status($event, $token);
        if ($cart->state === 'none' || $cart->seats === []) {
            return CheckoutResult::fail('expired');
        }

        $hash = hash('sha256', $token);

        $existing = $this->pendingOrderFor($event, $hash);
        if ($existing) {
            return $this->resume($existing);
        }

        // Everything the buyer can get wrong is checked BEFORE the cart is locked.
        [$people, $own, $problems] = $this->cleanAttendees($attendees, $cart->seats, $ownSeatKey);
        if ($problems !== []) {
            return CheckoutResult::fail('invalid_attendees', $problems);
        }

        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = Coupon::where('event_id', $event->id)->where('code', strtoupper(trim($couponCode)))->first();
            if (! $coupon) {
                return CheckoutResult::fail('coupon_invalid');
            }
        }

        $hold = $this->holds->beginPayment($event, $token);
        if (! $hold->ok) {
            return CheckoutResult::fail($hold->reason, $hold->conflicts);
        }
        if ($hold->seats !== $cart->seats) {
            $this->holds->abortPayment($event, $token);

            return CheckoutResult::fail('cart_changed');
        }

        try {
            $outcome = DB::transaction(function () use ($event, $hold, $people, $own, $email, $coupon, $account, $hash, $terms) {
                $seats = $event->seats()->whereIn('seat_key', $hold->seats)->get()->keyBy('seat_key');

                if ($seats->count() !== count($hold->seats) || $seats->contains(fn (EventSeat $seat) => $seat->price_cents === null)) {
                    return 'seats_not_sellable';
                }

                // Claimed last, so a failure above leaves nothing to undo. A later exception
                // rolls this claim back with the rest of the transaction.
                if ($coupon !== null && ! $coupon->claim()) {
                    return 'coupon_unavailable';
                }

                $order = new Order(['buyer_name' => $people[$own]['name'], 'buyer_email' => $email]);
                $order->forceFill([
                    'type' => Order::TYPE_PURCHASE,
                    'event_id' => $event->id,
                    'attendee_account_id' => $account?->id,
                    'currency' => $event->currency,
                    'coupon_id' => $coupon?->id,
                    'status' => Order::STATUS_PENDING,
                    'expires_at' => Carbon::createFromTimestampMs($hold->expiresAtMs),
                    'cart_token_hash' => $hash,
                ])->save();

                foreach ($hold->seats as $seatKey) {
                    $seat = $seats[$seatKey];
                    $list = (int) $seat->price_cents;
                    $discount = FeeCalculator::discountFor($coupon, $list); // coupons apply per ticket
                    $paid = $list - $discount;
                    $fees = FeeCalculator::feesFor($terms, Ticket::KIND_PAID, $paid);

                    $ticket = new Ticket([
                        'attendee_name' => $people[$seatKey]['name'],
                        'attendee_email' => $seatKey === $own ? $email : $people[$seatKey]['email'],
                    ]);
                    $ticket->forceFill([
                        'order_id' => $order->id,
                        'event_id' => $event->id,
                        'event_seat_id' => $seat->id,
                        'kind' => Ticket::KIND_PAID,
                        'status' => Ticket::STATUS_PENDING,
                        'is_buyer_seat' => $seatKey === $own,
                        'list_price_cents' => $list,
                        'discount_cents' => $discount,
                        'price_paid_cents' => $paid,
                        ...$fees,
                    ])->save();
                }

                $order->recalculateTotals();

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests for the same cart raced and the index let only one through.
            $existing = $this->pendingOrderFor($event, $hash);

            return $existing ? $this->resume($existing) : CheckoutResult::fail('error');
        } catch (Throwable $e) {
            $this->holds->abortPayment($event, $token);

            throw $e;
        }

        if (is_string($outcome)) {
            $this->holds->abortPayment($event, $token);

            return CheckoutResult::fail($outcome);
        }

        /** @var Order $order */
        $order = $outcome;

        // Nothing to pay (a 100% coupon): complete the order right now.
        if ($order->total_cents === 0) {
            if (! $this->completeOrder($order)) {
                $this->cancelOrder($order);
                $this->holds->abortPayment($event, $token);

                return CheckoutResult::fail('seat_lost');
            }

            $this->holds->release($event, $token); // database first, release second

            return new CheckoutResult(true, 'ok', $order->refresh());
        }

        try {
            $intent = $this->provider->createPayment($order);
        } catch (Throwable $e) {
            report($e);
            $this->cancelOrder($order);
            $this->holds->abortPayment($event, $token);

            return CheckoutResult::fail('payment_unavailable');
        }

        $payment = new Payment();
        $payment->forceFill([
            'order_id' => $order->id,
            'provider' => $this->provider->name(),
            'provider_reference' => $intent['reference'],
            'amount_cents' => $order->total_cents,
            'currency' => $order->currency,
            'status' => Payment::STATUS_PENDING,
        ])->save();

        return new CheckoutResult(true, 'ok', $order->refresh(), $payment, $intent['client_secret'] ?? null);
    }

    /**
     * The buyer backed out of paying (or wants to change something): cancel the pending order,
     * give the coupon back and unlock the cart so seats can be edited again.
     */
    public function abort(Event $event, string $token): HoldResult
    {
        $order = $this->pendingOrderFor($event, hash('sha256', $token));

        if ($order) {
            $this->cancelOrder($order);
            $this->cancelPendingPayments($order);
        }

        return $this->holds->abortPayment($event, $token);
    }

    /**
     * The provider says money arrived (the webhook). Safe to call twice.
     *
     * Money that arrives for an expired order is still real money: if the seats are still free
     * the order is honored, otherwise the payment is refunded. The database decides which.
     */
    public function handlePaymentSucceeded(string $reference): CheckoutResult
    {
        $payment = Payment::where('provider_reference', $reference)->first();

        if (! $payment) {
            return CheckoutResult::fail('unknown_payment');
        }

        $outcome = DB::transaction(function () use ($payment) {
            // Lock order is always payment first, then order.
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Payment::STATUS_SUCCEEDED) {
                return 'paid'; // replayed webhook
            }
            if (in_array($locked->status, [Payment::STATUS_REFUND_PENDING, Payment::STATUS_REFUNDED], true)) {
                return 'refund'; // already decided: this money goes back
            }

            // pending, or failed/expired earlier: money really arrived, so that is what counts
            $locked->forceFill([
                'status' => Payment::STATUS_SUCCEEDED,
                'succeeded_at' => now(),
                'failure_reason' => null,
            ])->save();

            if ($this->completeOrder($locked->order)) {
                return 'paid';
            }

            // Money was taken but the seats cannot be given. Queue the refund in the same
            // transaction so the two can never disagree.
            $locked->forceFill([
                'status' => Payment::STATUS_REFUND_PENDING,
                'refund_reason' => 'seat_unavailable',
            ])->save();
            $this->cancelOrder($locked->order);

            return 'refund';
        });

        $payment->refresh();
        $order = $payment->order()->first();

        if ($outcome === 'paid') {
            return new CheckoutResult(true, 'ok', $order, $payment);
        }

        // The refund call talks to the provider, so it happens outside the database transaction.
        $this->settleRefund($payment);
        $payment->refresh();

        return new CheckoutResult(
            false,
            $payment->status === Payment::STATUS_REFUNDED ? 'refunded' : 'refund_pending',
            $order->refresh(),
            $payment,
        );
    }

    /** The provider says the payment failed. Cancels the order; the buyer can retry with the same cart. */
    public function handlePaymentFailed(string $reference, string $reason = 'declined'): CheckoutResult
    {
        $payment = Payment::where('provider_reference', $reference)->first();

        if (! $payment) {
            return CheckoutResult::fail('unknown_payment');
        }

        DB::transaction(function () use ($payment, $reason) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Never downgrade: a payment that already succeeded or was refunded stays that way.
            if ($locked->status === Payment::STATUS_PENDING) {
                $locked->forceFill([
                    'status' => Payment::STATUS_FAILED,
                    'failure_reason' => mb_substr($reason, 0, 120),
                ])->save();
            }
        });

        $this->cancelOrder($payment->order()->first());

        return new CheckoutResult(true, 'payment_failed', $payment->order()->first(), $payment->refresh());
    }

    /**
     * Turn an order's tickets into valid, seat-owning tickets. The one place a sale is committed.
     * Returns false (changing nothing) if any seat can no longer be given to this order.
     * Also revives a cancelled order for a late payment.
     */
    public function completeOrder(Order $order): bool
    {
        try {
            // Nested inside another transaction this is a savepoint, so a rejected seat rolls back
            // only this attempt and the caller's transaction stays usable.
            return DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === Order::STATUS_PAID) {
                    return true;
                }
                if (! in_array($locked->status, [Order::STATUS_PENDING, Order::STATUS_CANCELLED], true)) {
                    return false;
                }

                $tickets = $locked->tickets()
                    ->whereIn('status', [Ticket::STATUS_PENDING, Ticket::STATUS_CANCELLED])
                    ->get();

                if ($tickets->isEmpty()) {
                    return false;
                }

                $seatIds = $tickets->pluck('event_seat_id')->filter()->all();
                $seats = EventSeat::whereIn('id', $seatIds)->lockForUpdate()->get();

                // Blocked by the organizer, or already sold, in the meantime.
                if ($seats->count() !== count($seatIds)
                    || $seats->contains(fn (EventSeat $seat) => $seat->status !== EventSeat::STATUS_AVAILABLE)) {
                    return false;
                }

                if ($locked->status === Order::STATUS_CANCELLED) {
                    // Reviving: the cancel gave the coupon back, so take it again. Best effort,
                    // because the buyer already paid the discounted price either way.
                    $locked->coupon?->claim();
                }

                // The partial unique index is the final arbiter: if another valid ticket owns
                // one of these seats, this throws and the whole attempt rolls back.
                $locked->tickets()->whereIn('id', $tickets->pluck('id'))->update(['status' => Ticket::STATUS_VALID]);
                EventSeat::whereIn('id', $seatIds)->update(['status' => EventSeat::STATUS_SOLD]);
                $locked->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** Cancel a pending order: tickets cancelled, coupon redemption given back. No-op for any other status. */
    public function cancelOrder(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Order::STATUS_PENDING) {
                return false;
            }

            $locked->tickets()->where('status', Ticket::STATUS_PENDING)->update(['status' => Ticket::STATUS_CANCELLED]);
            $locked->forceFill(['status' => Order::STATUS_CANCELLED])->save();
            $locked->coupon?->release();

            return true;
        });
    }

    /** Housekeeping: cancel pending orders whose payment window has ended. Returns how many. */
    public function expireStale(): int
    {
        $count = 0;

        Order::where('status', Order::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    if ($this->cancelOrder($order)) {
                        $this->cancelPendingPayments($order);
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** Housekeeping: retry refunds that could not be sent earlier. Returns how many were attempted. */
    public function retryPendingRefunds(): int
    {
        $attempted = 0;

        Payment::where('status', Payment::STATUS_REFUND_PENDING)->each(function (Payment $payment) use (&$attempted) {
            $this->settleRefund($payment);
            $attempted++;
        });

        return $attempted;
    }

    // ---------------------------------------------------------------- internals

    private function pendingOrderFor(Event $event, string $hash): ?Order
    {
        return Order::where('event_id', $event->id)
            ->where('cart_token_hash', $hash)
            ->where('status', Order::STATUS_PENDING)
            ->first();
    }

    private function resume(Order $order): CheckoutResult
    {
        $payment = $order->payments()->where('status', Payment::STATUS_PENDING)->latest('id')->first();

        // The provider's client secret is not stored. A real provider will re-fetch it here.
        return new CheckoutResult(true, 'ok', $order, $payment);
    }

    /** Send a queued refund to the provider. Safe to repeat: the provider gets a fixed idempotency key. */
    private function settleRefund(Payment $payment): void
    {
        $fresh = Payment::whereKey($payment->id)->first();

        if (! $fresh || $fresh->status !== Payment::STATUS_REFUND_PENDING) {
            return;
        }

        try {
            $this->provider->refund(
                $fresh->provider_reference,
                $fresh->amount_cents - $fresh->refunded_cents,
                $fresh->refund_reason ?? 'refund',
                'refund-'.$fresh->id,
            );
        } catch (Throwable $e) {
            report($e);

            return; // stays refund_pending; checkout:housekeeping retries it
        }

        $fresh->forceFill(['status' => Payment::STATUS_REFUNDED, 'refunded_cents' => $fresh->amount_cents])->save();
    }

    private function cancelPendingPayments(Order $order): void
    {
        foreach ($order->payments()->where('status', Payment::STATUS_PENDING)->get() as $payment) {
            try {
                $this->provider->cancelPayment($payment->provider_reference);
            } catch (Throwable $e) {
                report($e);
            }

            // A late success for this payment is still honored (see handlePaymentSucceeded).
            $payment->forceFill(['status' => Payment::STATUS_FAILED, 'failure_reason' => 'cancelled'])->save();
        }
    }

    /**
     * @param array<string, mixed> $attendees
     * @param string[] $cartSeats
     * @return array{0: array<string, array{name: string, email: ?string}>, 1: string, 2: string[]}
     */
    private function cleanAttendees(array $attendees, array $cartSeats, string $ownSeatKey): array
    {
        $problems = [];
        $people = [];

        foreach ($attendees as $key => $data) {
            $seat = strtoupper(trim((string) $key));

            if (! in_array($seat, $cartSeats, true)) {
                $problems[] = 'unknown_seat:'.$seat;
                continue;
            }

            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 120) {
                $problems[] = 'name:'.$seat;
            }

            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
                $problems[] = 'email:'.$seat;
            }

            $people[$seat] = ['name' => $name, 'email' => $email === '' ? null : $email];
        }

        foreach (array_diff($cartSeats, array_keys($people)) as $seat) {
            $problems[] = 'missing:'.$seat;
        }

        $own = strtoupper(trim($ownSeatKey));
        if (! in_array($own, $cartSeats, true)) {
            $problems[] = 'own_seat';
        }

        return [$people, $own, $problems];
    }
}