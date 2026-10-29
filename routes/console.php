<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cancel online orders not paid within the payment window (restores stock).
// Locally: `php artisan schedule:work`. On a server: run `php artisan schedule:run` every minute (cron / Task Scheduler).
Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping();

// Cart holds that ran out (also released lazily on shop requests; see App\Services\Cart\Reservations).
Schedule::command('cart:release-expired')->everyMinute()->withoutOverlapping();
