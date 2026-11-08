<?php

namespace App\Console\Commands;

use App\Services\Loyalty\PointsService;
use Illuminate\Console\Command;

/**
 * Expire loyalty points older than config('loyalty.expiry_months') (oldest first). Scheduled daily at 00:30
 * Malaysia time in routes/console.php; locally the scheduler needs `php artisan schedule:work`.
 */
class LoyaltyExpire extends Command
{
    protected $signature = 'loyalty:expire';

    protected $description = 'Expire loyalty points that are past their expiry date';

    public function handle(PointsService $points): int
    {
        $expired = $points->expireDue();
        $this->info('Expired '.number_format($expired).' '.str('point')->plural($expired).'.');

        return self::SUCCESS;
    }
}
