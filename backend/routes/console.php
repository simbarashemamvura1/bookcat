<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cancels pending orders past their payment window and retries stuck refunds.
// In production the server must run the scheduler every minute.
Schedule::command('checkout:housekeeping')->everyMinute()->withoutOverlapping();