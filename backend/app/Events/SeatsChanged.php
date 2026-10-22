<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One message about a few seats on one event's public channel. It carries only what the public
 * seat map already shows: never a buyer, an email or a cart token.
 */
class SeatsChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param array<int, array{key: string, state: string, heldUntilMs: ?int}> $changes
     * @param int $at server time in ms, so a slow, older message can never overwrite a newer one
     */
    public function __construct(
        public readonly string $eventSlug,
        public readonly array $changes,
        public readonly int $at,
    ) {
    }

    /** @return Channel[] */
    public function broadcastOn(): array
    {
        return [new Channel('event.'.$this->eventSlug)];
    }

    public function broadcastAs(): string
    {
        return 'seats.changed';
    }

    public function broadcastWith(): array
    {
        return ['at' => $this->at, 'changes' => $this->changes];
    }
}