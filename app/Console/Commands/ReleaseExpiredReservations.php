<?php

namespace App\Console\Commands;

use App\Services\Cart\HoldWarnings;
use App\Services\Cart\Reservations;
use Illuminate\Console\Command;

/**
 * Ends cart holds whose time is up (scheduled every minute in routes/console.php). The same
 * release also runs lazily on cart/shop/product/checkout requests.
 * It also sends the "your hold expires soon" notifications (HoldWarnings), which need the scheduler.
 */
class ReleaseExpiredReservations extends Command
{
    protected $signature = 'cart:release-expired';

    protected $description = 'Release cart reservations whose hold time has run out';

    public function handle(Reservations $reservations, HoldWarnings $warnings): int
    {
        $released = $reservations->releaseExpired();
        $this->info("Released {$released} expired cart ".str('hold')->plural($released).'.');

        $warned = $warnings->send();
        $this->info("Sent {$warned} hold ".str('warning')->plural($warned).'.');

        return self::SUCCESS;
    }
}
