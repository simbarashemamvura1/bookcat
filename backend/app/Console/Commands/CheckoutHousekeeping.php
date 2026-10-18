<?php

namespace App\Console\Commands;

use App\Services\CheckoutService;
use Illuminate\Console\Command;

class CheckoutHousekeeping extends Command
{
    protected $signature = 'checkout:housekeeping';

    protected $description = 'Cancel expired pending orders and retry queued refunds';

    public function handle(CheckoutService $checkout): int
    {
        $this->info('Expired orders cancelled: '.$checkout->expireStale());
        $this->info('Refunds attempted: '.$checkout->retryPendingRefunds());

        return self::SUCCESS;
    }
}