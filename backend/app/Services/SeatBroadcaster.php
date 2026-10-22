<?php

namespace App\Services;

use App\Events\SeatsChanged;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Throwable;

/** The one place that tells watching browsers a seat changed. */
class SeatBroadcaster
{
    /** Someone is holding these seats until $untilMs (Redis clock, ms since epoch). */
    public function held(Event $event, array $seatKeys, int $untilMs): void
    {
        $this->send($event, $seatKeys, 'held', $untilMs);
    }

    /** These seats can be picked again. */
    public function available(Event $event, array $seatKeys): void
    {
        $this->send($event, $seatKeys, 'available', null);
    }

    /** Sold or blocked: the public sees both the same way. */
    public function unavailable(Event $event, array $seatKeys): void
    {
        $this->send($event, $seatKeys, 'unavailable', null);
    }

    private function send(Event $event, array $seatKeys, string $state, ?int $untilMs): void
    {
        $seatKeys = array_values(array_unique($seatKeys));

        if ($seatKeys === []) {
            return;
        }

        $changes = array_map(
            fn (string $key) => ['key' => $key, 'state' => $state, 'heldUntilMs' => $untilMs],
            $seatKeys,
        );
        $slug = $event->slug;
        $at = (int) floor(microtime(true) * 1000);

        // Announce only once the database change is final: a sale that rolls back must never be
        // broadcast. Outside a transaction this runs straight away.
        DB::afterCommit(function () use ($slug, $changes, $at) {
            try {
                event(new SeatsChanged($slug, $changes, $at));
            } catch (Throwable $e) {
                // Live updates are a convenience. A broadcast problem must never fail a sale or a hold.
                report($e);
            }
        });
    }
}