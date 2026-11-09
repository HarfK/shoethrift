<?php

namespace App\Services\Ml;

use App\Models\RevenueForecast;
use App\Services\Checkout\PlaceOrder;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Compares saved revenue forecasts with actual revenue for the last complete Malaysian days.
 * Actuals come from SalesReport, the same figures as the dashboard and reports (paid orders,
 * dated by paid_at in Malaysia time, net of refunds).
 *
 * Per day, a forecast saved at the time always wins over a backfilled one, and among those the most
 * recent one made before that day is used. Backfilled forecasts are measured separately: they
 * were not saved at the time, so they don't count towards the main MAE and WAPE.
 */
class ForecastAccuracy
{
    public const DAYS = 14;

    /**
     * @return array{from: string, to: string, rows: list<array<string, mixed>>, saved: array<string, mixed>, backfilled: array<string, mixed>}
     */
    public function report(int $days = self::DAYS): array
    {
        $today = ReportPeriod::now()->startOfDay();
        $from = $today->subDays($days);
        $to = $today->subDay();

        $actuals = (new SalesReport(ReportPeriod::custom($from, $to)))->overTime('day');
        $saved = RevenueForecast::query()
            ->whereBetween('forecast_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('made_on')
            ->get()
            ->groupBy('forecast_date');

        $rows = [];
        foreach ($actuals as $date => $actual) {
            $rows[] = $this->row($date, $actual['net'], $this->choose($saved->get($date, collect()), $date));
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'rows' => $rows,
            'saved' => $this->summary(array_filter($rows, fn (array $row) => $row['forecast'] !== null && ! $row['backfilled'])),
            'backfilled' => $this->summary(array_filter($rows, fn (array $row) => $row['forecast'] !== null && $row['backfilled'])),
        ];
    }

    /**
     * @param  Collection<int, RevenueForecast>  $candidates  oldest first
     */
    private function choose(Collection $candidates, string $date): ?RevenueForecast
    {
        $before = $candidates->filter(fn (RevenueForecast $forecast) => $forecast->made_on < $date);

        return $before->where('is_backfill', false)->last() ?? $before->last();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $date, string $actual, ?RevenueForecast $forecast): array
    {
        $row = [
            'date' => $date,
            'weekday' => ReportPeriod::day(CarbonImmutable::parse($date, ReportPeriod::timezone()))->start->englishDayOfWeek,
            'forecast' => null,
            'actual' => $actual,
            'difference' => null,
            'percent' => null,
            'backfilled' => false,
            'made_on' => null,
        ];
        if ($forecast === null) {
            return $row;
        }

        $actualSen = PlaceOrder::toSen($actual);
        $differenceSen = $actualSen - PlaceOrder::toSen($forecast->forecast);

        return [
            ...$row,
            'forecast' => $forecast->forecast,
            'difference' => PlaceOrder::fromSen($differenceSen),
            'percent' => $actualSen !== 0 ? round($differenceSen / $actualSen * 100, 1) : null,
            'backfilled' => $forecast->is_backfill,
            'made_on' => $forecast->made_on,
        ];
    }

    /**
     * MAE = average absolute difference in RM. WAPE = total absolute difference / total actual revenue.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{days: int, mae: ?string, wape: ?float}
     */
    private function summary(array $rows): array
    {
        if ($rows === []) {
            return ['days' => 0, 'mae' => null, 'wape' => null];
        }

        $absolute = array_sum(array_map(fn (array $row) => abs(PlaceOrder::toSen($row['difference'])), $rows));
        $actual = array_sum(array_map(fn (array $row) => PlaceOrder::toSen($row['actual']), $rows));

        return [
            'days' => count($rows),
            'mae' => PlaceOrder::fromSen((int) round($absolute / count($rows))),
            'wape' => $actual > 0 ? round($absolute / $actual * 100, 1) : null,
        ];
    }
}
