<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventSeat;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SeatHoldService
{
    /** A fresh cart token. The API layer keeps it in an httpOnly session cookie, never in the URL. */
    public function newToken(): string
    {
        return Str::random(40);
    }

    /**
     * Add seats to the buyer's cart, all or nothing. Idempotent for seats already held by this token.
     */
    public function select(Event $event, string $token, array $seatKeys): HoldResult
    {
        $this->assertToken($token);
        [$seatKeys, $malformed] = $this->normalize($seatKeys);

        if ($malformed !== []) {
            return HoldResult::fail('invalid_seats', $malformed);
        }
        if ($seatKeys === []) {
            return HoldResult::fail('empty');
        }
        if (! $event->isPurchasable()) {
            return HoldResult::fail('event_not_purchasable');
        }

        $known = $event->seats()->whereIn('seat_key', $seatKeys)->pluck('seat_key')->all();
        $unknown = array_values(array_diff($seatKeys, $known));
        if ($unknown !== []) {
            return HoldResult::fail('invalid_seats', $unknown);
        }

        $before = $this->status($event, $token)->seats;

        $reply = $this->run(SeatHoldScripts::SELECT, $event, $token, $seatKeys, [$token, $this->windowMs(), $this->maxSeats()]);

        switch ((int) ($reply[0] ?? -99)) {
            case 1:
                break;
            case 0:
                return HoldResult::fail('seats_unavailable', array_map('strval', array_slice($reply, 1)));
            case -1:
                return HoldResult::fail('cart_locked');
            case -2:
                return HoldResult::fail('too_many_seats');
            default:
                return HoldResult::fail('error');
        }

        // Locks are held. Now check the database. A sale commits to Postgres BEFORE its hold is
        // released, so a seat that has been sold can never pass this check.
        $notSellable = $this->notSellable($event, $seatKeys);
        if ($notSellable !== []) {
            // Keep the all-or-nothing promise: undo what this call added, plus the bad seats.
            $undo = array_values(array_unique(array_merge($notSellable, array_diff($seatKeys, $before))));
            $this->releaseSeats($event, $token, $undo, true);

            return HoldResult::fail('seats_not_sellable', $notSellable);
        }

        return $this->status($event, $token);
    }

    /** Release specific seats (the buyer un-clicked them). Refused while payment is in progress. */
    public function deselect(Event $event, string $token, array $seatKeys): HoldResult
    {
        $this->assertToken($token);
        [$seatKeys, $malformed] = $this->normalize($seatKeys);

        if ($malformed !== []) {
            return HoldResult::fail('invalid_seats', $malformed);
        }
        if ($seatKeys === []) {
            return HoldResult::fail('empty');
        }

        $reply = $this->releaseSeats($event, $token, $seatKeys, false);

        if ((int) ($reply[0] ?? 0) === -1) {
            return HoldResult::fail('cart_locked');
        }

        return $this->status($event, $token);
    }

    /** The current cart: seats, state and how long is left. state 'none' means no live cart. */
    public function status(Event $event, string $token): HoldResult
    {
        $this->assertToken($token);

        $reply = Redis::connection()->eval(SeatHoldScripts::STATUS, 1, $this->cartKey($event, $token));

        return HoldResult::fromStatus((array) $reply);
    }

    /**
     * The buyer pressed Pay. Locks the cart and guarantees at least payment_grace_minutes from now,
     * even if the original timer was nearly out. The seat list comes from the CART, never from the client.
     */
    public function beginPayment(Event $event, string $token): HoldResult
    {
        $this->assertToken($token);

        $current = $this->status($event, $token);

        if (! $event->isPurchasable()) {
            // A freeze or deactivation stops new payments; a cart already paying keeps its seats.
            return $current->state === 'paying' ? $current : HoldResult::fail('event_not_purchasable');
        }
        if ($current->state === 'none' || $current->seats === []) {
            return HoldResult::fail('expired');
        }

        // The organizer may have blocked a seat while this buyer held it.
        $notSellable = $this->notSellable($event, $current->seats);
        if ($notSellable !== []) {
            return HoldResult::fail('seats_not_sellable', $notSellable);
        }

        $reply = $this->run(SeatHoldScripts::BEGIN_PAYMENT, $event, $token, $current->seats, [$token, $this->graceMs()]);

        return match ((int) ($reply[0] ?? 0)) {
            1 => $this->status($event, $token),
            -3 => HoldResult::fail('expired'),
            -4 => HoldResult::fail('cart_changed'),
            -5 => HoldResult::fail('seat_lost'),
            default => HoldResult::fail('error'),
        };
    }

    /** Payment failed or was cancelled: unlock the cart so the buyer can retry or change seats. */
    public function abortPayment(Event $event, string $token): HoldResult
    {
        $this->assertToken($token);

        Redis::connection()->eval(SeatHoldScripts::ABORT_PAYMENT, 1, $this->cartKey($event, $token));

        return $this->status($event, $token);
    }

    /**
     * Drop the whole cart. Used when the buyer abandons checkout, and AFTER a sale has been
     * committed to the database (database first, release second, so a sold seat is never re-held).
     */
    public function release(Event $event, string $token): void
    {
        $seats = $this->status($event, $token)->seats;

        if ($seats !== []) {
            $this->releaseSeats($event, $token, $seats, true);
        }
    }

    /**
     * What the seat map needs: seats that are sold/blocked (database) and seats currently held
     * with the time each hold ends (Redis). Clients can expire holds themselves using those times.
     *
     * @return array{unavailable: array<string,string>, held: array<string,int>}
     */
    public function availability(Event $event): array
    {
        $reply = (array) Redis::connection()->eval(SeatHoldScripts::AVAILABILITY, 1, $this->heldKey($event));

        $held = [];
        for ($i = 0; $i + 1 < count($reply); $i += 2) {
            $held[(string) $reply[$i]] = (int) $reply[$i + 1];
        }

        $unavailable = $event->seats()
            ->where('status', '!=', EventSeat::STATUS_AVAILABLE)
            ->pluck('status', 'seat_key')
            ->all();

        return ['unavailable' => $unavailable, 'held' => $held];
    }

    // ---------------------------------------------------------------- internals

    private function releaseSeats(Event $event, string $token, array $seatKeys, bool $allowPaying): array
    {
        return $this->run(SeatHoldScripts::DESELECT, $event, $token, $seatKeys, [$token, $allowPaying ? '1' : '0']);
    }

    /** Seats that cannot be sold right now: sold, blocked by the organizer, or not priced yet. */
    private function notSellable(Event $event, array $seatKeys): array
    {
        return $event->seats()
            ->whereIn('seat_key', $seatKeys)
            ->where(fn ($q) => $q->where('status', '!=', EventSeat::STATUS_AVAILABLE)->orWhereNull('price_cents'))
            ->pluck('seat_key')
            ->all();
    }

    /** Every Redis access goes through a script, so key prefixes are applied consistently. */
    private function run(string $script, Event $event, string $token, array $seatKeys, array $args): array
    {
        $keys = [$this->cartKey($event, $token), $this->heldKey($event)];
        foreach ($seatKeys as $seatKey) {
            $keys[] = $this->lockKey($event, $seatKey);
        }

        return (array) Redis::connection()->eval($script, count($keys), ...$keys, ...$args, ...$seatKeys);
    }

    // The {event<id>} part is a Redis hash tag: every key of one event lands on the same
    // Redis Cluster slot, so these scripts keep working if we ever move to a clustered Redis.
    private function cartKey(Event $event, string $token): string
    {
        return 'bookcat:{event'.$event->id.'}:cart:'.$token;
    }

    private function heldKey(Event $event): string
    {
        return 'bookcat:{event'.$event->id.'}:held';
    }

    private function lockKey(Event $event, string $seatKey): string
    {
        return 'bookcat:{event'.$event->id.'}:seat:'.$seatKey;
    }

    /** @return array{0: string[], 1: string[]} [clean unique keys, malformed keys] */
    private function normalize(array $seatKeys): array
    {
        $clean = [];
        $malformed = [];

        foreach ($seatKeys as $key) {
            $key = strtoupper(trim((string) $key));

            if (preg_match('/^[A-Z0-9]+(-[A-Z0-9]+){0,2}$/', $key)) {
                $clean[$key] = true;
            } else {
                $malformed[] = $key;
            }
        }

        return [array_keys($clean), $malformed];
    }

    private function assertToken(string $token): void
    {
        if (! preg_match('/^[A-Za-z0-9]{32,64}$/', $token)) {
            throw new InvalidArgumentException('Malformed hold token.');
        }
    }

    private function windowMs(): int
    {
        $min = (float) config('bookcat.holds.min_minutes');
        $max = (float) config('bookcat.holds.max_minutes');
        $minutes = max($min, min($max, (float) config('bookcat.holds.minutes')));

        return max(1000, (int) round($minutes * 60000));
    }

    private function graceMs(): int
    {
        return max(0, (int) round((float) config('bookcat.holds.payment_grace_minutes') * 60000));
    }

    private function maxSeats(): int
    {
        return max(1, (int) config('bookcat.holds.max_seats'));
    }
}