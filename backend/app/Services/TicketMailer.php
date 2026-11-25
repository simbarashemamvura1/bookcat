<?php

namespace App\Services;

use App\Jobs\SendOrderTickets;
use App\Mail\TicketsEmail;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class TicketMailer
{
    /** Orders paid longer ago than this are never swept, so old orders are not mailed by surprise. */
    private const SWEEP_WINDOW_DAYS = 7;

    /** Give the normal job a head start before the sweep steps in. */
    private const SWEEP_GRACE_MINUTES = 2;

    /**
     * Sends whatever is still owed for one paid order. Safe to call any number of times:
     * every email is recorded the moment it is sent, so a retry only sends what is missing.
     * Delivery is at-least-once: a crash between sending and recording can repeat one email.
     */
    public function send(int $orderId): void
    {
        // One worker per order at a time. Losing the lock is fine: the winner does the work,
        // and the sweep covers the case where the winner dies.
        Cache::lock('tickets-email:'.$orderId, 120)->get(function () use ($orderId) {
            $this->sendLocked($orderId);
        });
    }

    /**
     * Housekeeping: queue the email for paid orders whose buyer email never went out
     * (for example the job was lost). Each order is retried at most every 15 minutes.
     * Returns how many were queued.
     */
    public function sweep(): int
    {
        $queued = 0;

        Order::where('status', Order::STATUS_PAID)
            ->whereNull('tickets_emailed_at')
            ->whereBetween('paid_at', [
                now()->subDays(self::SWEEP_WINDOW_DAYS),
                now()->subMinutes(self::SWEEP_GRACE_MINUTES),
            ])
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$queued) {
                foreach ($orders as $order) {
                    if (Cache::add('tickets-sweep:'.$order->id, 1, now()->addMinutes(15))) {
                        SendOrderTickets::dispatch($order->id);
                        $queued++;
                    }
                }
            });

        return $queued;
    }

    private function sendLocked(int $orderId): void
    {
        $order = Order::with(['event.floorPlan.venue', 'tickets.eventSeat'])->find($orderId);

        // Only paid orders get tickets. A refunded or cancelled order sends nothing.
        if (! $order || $order->status !== Order::STATUS_PAID) {
            return;
        }

        $valid = $order->tickets->where('status', Ticket::STATUS_VALID)->values();

        if ($valid->isEmpty()) {
            return;
        }

        // 1. The buyer gets every ticket on the order.
        if ($order->tickets_emailed_at === null) {
            Mail::to($order->buyer_email)->send(new TicketsEmail($order, $valid, true));
            $order->forceFill(['tickets_emailed_at' => now()])->save();
        }

        // 2. Each additional attendee who gave an email gets their own ticket.
        $failed = 0;

        foreach ($valid as $ticket) {
            if ($ticket->is_buyer_seat || $ticket->attendee_email === null || $ticket->attendee_emailed_at !== null) {
                continue;
            }

            try {
                // An attendee who used the buyer's own address already has it in the buyer's email.
                if ($ticket->attendee_email !== $order->buyer_email) {
                    Mail::to($ticket->attendee_email)
                        ->send(new TicketsEmail($order, new EloquentCollection([$ticket]), false));
                }

                $ticket->forceFill(['attendee_emailed_at' => now()])->save();
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        if ($failed > 0) {
            // Makes the job retry. Emails already sent are recorded, so only the failures repeat.
            throw new RuntimeException($failed.' attendee email(s) failed for order '.$orderId.'.');
        }
    }
}