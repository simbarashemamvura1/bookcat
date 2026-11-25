<?php

namespace App\Jobs;

use App\Services\TicketMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class SendOrderTickets implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    public function __construct(public int $orderId)
    {
    }

    /** @return int[] seconds to wait before each retry */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(TicketMailer $mailer): void
    {
        $mailer->send($this->orderId);
    }
}