<?php

namespace App\Console\Commands;

use App\Services\Ml\MlService;
use Illuminate\Console\Command;

/**
 * Asks the ML API to retrain all models in the background (scheduled every Sunday 03:00 Malaysia time
 * in routes/console.php; locally the scheduler needs `php artisan schedule:work`).
 */
class MlRetrain extends Command
{
    protected $signature = 'ml:retrain';

    protected $description = 'Retrain the ML models (revenue forecast, restock, price) via the ML API';

    public function handle(MlService $ml): int
    {
        $result = $ml->retrain();

        if ($result->ok()) {
            $this->info('Retraining started in the background on the ML service (about 1 minute).');

            return self::SUCCESS;
        }

        $this->error('Retrain could not be started: '.$result->error);

        return self::FAILURE;
    }
}
