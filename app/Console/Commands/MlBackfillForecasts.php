<?php

namespace App\Console\Commands;

use App\Models\RevenueForecast;
use App\Services\Ml\ForecastAccuracy;
use App\Services\Reports\ReportPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Re-creates forecasts for recent days that have none (ml-service/backfill_forecasts.py), using a
 * model refitted only on sales before each week. Rows are marked is_backfill, days that already have
 * a forecast are left alone, and the ML API doesn't need to be running (MySQL does).
 */
class MlBackfillForecasts extends Command
{
    protected $signature = 'ml:backfill-forecasts
        {--days='.ForecastAccuracy::DAYS.' : How many complete days to go back}
        {--replace : Re-create days that were backfilled before (saved forecasts are never touched)}';

    protected $description = 'Re-create revenue forecasts for recent past days, marked as backfilled';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $today = ReportPeriod::now()->format('Y-m-d');
        $python = base_path(PHP_OS_FAMILY === 'Windows' ? 'ml-service/venv/Scripts/python.exe' : 'ml-service/venv/bin/python');

        $this->info('Refitting the model for each week (this can take a few minutes) ...');
        $process = Process::path(base_path('ml-service'))->timeout(900)
            ->run([$python, 'backfill_forecasts.py', "--days={$days}", "--today={$today}"]);

        if (! $process->successful()) {
            $this->error('The backfill script failed: '.trim($process->errorOutput()));

            return self::FAILURE;
        }

        $output = json_decode($process->output(), true);
        if (! is_array($output['rows'] ?? null)) {
            $this->error('The backfill script returned something unexpected.');

            return self::FAILURE;
        }

        $dates = array_column($output['rows'], 'date');
        $saved = DB::transaction(function () use ($output, $dates) {
            if ($this->option('replace')) {
                RevenueForecast::query()->where('is_backfill', true)->whereIn('forecast_date', $dates)->delete();
            }

            $existing = RevenueForecast::query()->whereIn('forecast_date', $dates)->pluck('forecast_date')->flip();
            $saved = 0;
            foreach ($output['rows'] as $row) {
                if (isset($existing[$row['date']])) {
                    continue;
                }
                RevenueForecast::create([
                    'forecast_date' => $row['date'], 'made_on' => $row['made_on'], 'forecast' => $row['forecast'],
                    'lower' => $row['lower'], 'upper' => $row['upper'], 'is_backfill' => true,
                    'model' => $output['model'] ?? null, 'trained_until' => $row['trained_until'],
                ]);
                $saved++;
            }

            return $saved;
        });

        $this->info("Backfilled {$saved} days; ".(count($output['rows']) - $saved).' already had a forecast.');

        return self::SUCCESS;
    }
}
