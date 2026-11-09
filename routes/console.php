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

// Expire loyalty points older than config('loyalty.expiry_months'), daily at 00:30 Malaysia time.
Schedule::command('loyalty:expire')->dailyAt('00:30')->timezone(config('app.display_timezone', 'Asia/Kuala_Lumpur'));

// Retrain the ML models (ml-service API, POST /admin/retrain) every Sunday at 03:00 Malaysia time.
Schedule::command('ml:retrain')->weeklyOn(0, '03:00')->timezone(config('app.display_timezone', 'Asia/Kuala_Lumpur'));

// Save the day's revenue forecast for "Forecast vs actual". Hourly, but only the first success of each day is kept.
Schedule::command('ml:save-forecast')->hourly()->withoutOverlapping();

// Cart holds that ran out (also released lazily on shop requests; see App\Services\Cart\Reservations).
Schedule::command('cart:release-expired')->everyMinute()->withoutOverlapping();
