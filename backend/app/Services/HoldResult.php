<?php

namespace App\Services;

final class HoldResult
{
    /**
     * @param string $reason  ok | empty | event_not_purchasable | invalid_seats | seats_unavailable
     *                        | seats_not_sellable | too_many_seats | cart_locked | expired
     *                        | cart_changed | seat_lost | error
     * @param string $state   none | selecting | paying
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = 'ok',
        public readonly array $seats = [],        // seat keys currently in the cart
        public readonly array $conflicts = [],    // seat keys that caused a failure
        public readonly ?int $expiresAtMs = null, // Redis server time, ms since epoch
        public readonly ?int $remainingMs = null, // what a client countdown should use
        public readonly string $state = 'none',
    ) {
    }

    public static function fail(string $reason, array $conflicts = []): self
    {
        return new self(false, $reason, [], array_values($conflicts));
    }

    public static function fromStatus(array $reply): self
    {
        if ($reply === []) {
            return new self(true);
        }

        $seats = array_map('strval', array_slice($reply, 3));
        sort($seats);

        return new self(true, 'ok', $seats, [], (int) $reply[1], (int) $reply[2], (string) $reply[0]);
    }
}