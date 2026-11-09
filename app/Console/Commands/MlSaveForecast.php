<?php

namespace App\Console\Commands;

use App\Models\RevenueForecast;
use App\Services\Ml\MlService;
use Illuminate\Console\Command;

/**
 * Saves today's forecast for the coming days, so it can later be compared with actual revenue.
 * Scheduled hourly in routes/console.php: the first successful run of a Malaysian day is kept and
 * later runs change nothing, so it doesn't matter when the machine happens to be on.
 */
class MlSaveForecast extends Command
{
    protected $signature = 'ml:save-forecast';

    protected $description = 'Save the current revenue forecast from the ML API for later comparison with actual revenue';

    public function handle(MlService $ml): int
    {
        $result = $ml->forecast(RevenueForecast::SAVE_DAYS);

        if (! $result->ok()) {
            $this->error('Forecast could not be saved: '.$result->error);

            return self::FAILURE;
        }

        $saved = 0;
        foreach ($result->get('days', []) as $day) {
            $forecast = RevenueForecast::firstOrCreate(
                ['forecast_date' => $day['date'], 'made_on' => $result->get('today')],
                ['forecast' => $day['forecast'], 'lower' => $day['lower'] ?? null, 'upper' => $day['upper'] ?? null,
                    'is_backfill' => false, 'model' => $result->get('model'), 'trained_until' => $result->get('model_trained_until')],
            );
            $saved += $forecast->wasRecentlyCreated ? 1 : 0;
        }

        $this->info($saved > 0 ? "Saved {$saved} daily forecasts." : 'Today\'s forecast was already saved.');

        return self::SUCCESS;
    }
}
