<?php

namespace App\Console\Commands;

use App\Services\CheckoutService;
use App\Services\TicketMailer;
use Illuminate\Console\Command;

class CheckoutHousekeeping extends Command
{
    protected $signature = 'checkout:housekeeping';

    protected $description = 'Cancel expired pending orders, retry queued refunds and queue missing ticket emails';

    public function handle(CheckoutService $checkout, TicketMailer $tickets): int
    {
        $this->info('Expired orders cancelled: '.$checkout->expireStale());
        $this->info('Refunds attempted: '.$checkout->retryPendingRefunds());
        $this->info('Ticket emails queued: '.$tickets->sweep());

        return self::SUCCESS;
    }
}