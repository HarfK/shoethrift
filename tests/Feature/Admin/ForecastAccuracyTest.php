<?php

namespace Tests\Feature\Admin;

use App\Models\RevenueForecast;
use App\Models\User;
use App\Services\Ml\ForecastAccuracy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * "Forecast vs actual": saving forecasts, backfilling, and the comparison on the Revenue forecast page.
 * "Now" is Monday 9 Nov 2026, 10:00 in Kuala Lumpur, so the 14 complete days are 26 Oct – 8 Nov.
 */
class ForecastAccuracyTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 02:00:00', 'UTC'));
        config(['services.ml.url' => 'http://ml.test', 'services.ml.key' => 'test-ml-key']);
        $this->admin = User::factory()->admin()->create();
    }

    private function fakeForecastApi(float $forecast = 800): void
    {
        Http::fake(['ml.test/forecast/revenue*' => Http::response([
            'today' => '2026-11-09', 'model' => 'XGBoost + major-event uplift', 'model_trained_until' => '2026-11-07',
            'days' => array_map(fn (int $i) => ['date' => sprintf('2026-11-%02d', 9 + $i), 'forecast' => $forecast, 'lower' => 200.0, 'upper' => 1900.0], range(1, 7)),
        ])]);
    }

    private function forecast(string $date, float $amount, bool $backfill = false, string $madeOn = '2026-10-20'): RevenueForecast
    {
        return RevenueForecast::create(['forecast_date' => $date, 'made_on' => $madeOn, 'forecast' => $amount, 'is_backfill' => $backfill]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(array $report, string $date): array
    {
        return collect($report['rows'])->firstWhere('date', $date);
    }

    // --- saving -----------------------------------------------------------------------------------

    public function test_save_forecast_stores_the_coming_days_once_per_day(): void
    {
        $this->fakeForecastApi();

        $this->artisan('ml:save-forecast')->expectsOutputToContain('Saved 7 daily forecasts.')->assertSuccessful();

        $this->assertSame(7, RevenueForecast::count());
        $first = RevenueForecast::where('forecast_date', '2026-11-10')->sole();
        $this->assertSame('2026-11-09', $first->made_on);
        $this->assertSame('800.00', $first->forecast);
        $this->assertSame('200.00', $first->lower);
        $this->assertFalse($first->is_backfill);
        $this->assertSame('XGBoost + major-event uplift', $first->model);
        $this->assertSame('2026-11-07', $first->trained_until);

        // Later runs on the same day keep the first forecast.
        Http::swap(new Factory);
        $this->fakeForecastApi(999);
        $this->artisan('ml:save-forecast')->expectsOutputToContain('already saved')->assertSuccessful();
        $this->assertSame(7, RevenueForecast::count());
        $this->assertSame('800.00', RevenueForecast::where('forecast_date', '2026-11-10')->sole()->forecast);
    }

    public function test_save_forecast_saves_nothing_when_the_api_is_offline(): void
    {
        Http::fake(fn () => Http::failedConnection('Connection refused'));

        $this->artisan('ml:save-forecast')->assertFailed();

        $this->assertSame(0, RevenueForecast::count());
    }

    public function test_saving_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

        $this->assertStringContainsString('ml:save-forecast', $commands);
    }

    // --- backfill ---------------------------------------------------------------------------------

    public function test_backfill_marks_rows_and_never_touches_days_that_have_a_forecast(): void
    {
        $this->forecast('2026-10-27', 500, madeOn: '2026-10-26');
        Process::fake(['*' => Process::result(json_encode(['model' => 'XGBoost', 'rows' => [
            ['date' => '2026-10-26', 'made_on' => '2026-10-25', 'trained_until' => '2026-10-25', 'forecast' => 610.5, 'lower' => 100, 'upper' => 900],
            ['date' => '2026-10-27', 'made_on' => '2026-10-25', 'trained_until' => '2026-10-25', 'forecast' => 999, 'lower' => 100, 'upper' => 900],
        ]]))]);

        $this->artisan('ml:backfill-forecasts')->expectsOutputToContain('Backfilled 1 days; 1 already had a forecast.')->assertSuccessful();

        $new = RevenueForecast::where('forecast_date', '2026-10-26')->sole();
        $this->assertTrue($new->is_backfill);
        $this->assertSame('610.50', $new->forecast);
        $this->assertSame('2026-10-25', $new->made_on);
        $this->assertSame('500.00', RevenueForecast::where('forecast_date', '2026-10-27')->sole()->forecast);
        Process::assertRan(fn ($process) => in_array('--today=2026-11-09', $process->command, true) && in_array('--days=14', $process->command, true));

        // Running it again changes nothing.
        $this->artisan('ml:backfill-forecasts')->expectsOutputToContain('Backfilled 0 days')->assertSuccessful();
        $this->assertSame(2, RevenueForecast::count());
    }

    public function test_backfill_replace_only_redoes_backfilled_days(): void
    {
        $this->forecast('2026-10-26', 111, backfill: true, madeOn: '2026-10-25');
        $this->forecast('2026-10-27', 222, madeOn: '2026-10-26'); // saved at the time
        Process::fake(['*' => Process::result(json_encode(['model' => 'XGBoost', 'rows' => [
            ['date' => '2026-10-26', 'made_on' => '2026-10-25', 'trained_until' => '2026-10-25', 'forecast' => 610.5, 'lower' => 100, 'upper' => 900],
            ['date' => '2026-10-27', 'made_on' => '2026-10-25', 'trained_until' => '2026-10-25', 'forecast' => 999, 'lower' => 100, 'upper' => 900],
        ]]))]);

        $this->artisan('ml:backfill-forecasts', ['--replace' => true])->assertSuccessful();

        $this->assertSame('610.50', RevenueForecast::where('forecast_date', '2026-10-26')->sole()->forecast);
        $this->assertSame('222.00', RevenueForecast::where('forecast_date', '2026-10-27')->sole()->forecast);
    }

    public function test_a_failed_replace_keeps_the_existing_rows(): void
    {
        $this->forecast('2026-10-26', 111, backfill: true, madeOn: '2026-10-25');
        Process::fake(['*' => Process::result('', 'MySQL is not running', 1)]);

        $this->artisan('ml:backfill-forecasts', ['--replace' => true])->assertFailed();

        $this->assertSame('111.00', RevenueForecast::where('forecast_date', '2026-10-26')->sole()->forecast);
    }

    public function test_backfill_reports_a_failed_script_and_saves_nothing(): void
    {
        Process::fake(['*' => Process::result('', 'MySQL is not running', 1)]);
        $this->artisan('ml:backfill-forecasts')->expectsOutputToContain('MySQL is not running')->assertFailed();

        Process::fake(['*' => Process::result('not json')]);
        $this->artisan('ml:backfill-forecasts')->expectsOutputToContain('unexpected')->assertFailed();

        $this->assertSame(0, RevenueForecast::count());
    }

    // --- comparison -------------------------------------------------------------------------------

    public function test_the_window_is_the_last_14_complete_malaysian_days(): void
    {
        $report = app(ForecastAccuracy::class)->report();

        $this->assertCount(14, $report['rows']);
        $this->assertSame(['2026-10-26', '2026-11-08'], [$report['from'], $report['to']]);
        $this->assertSame('2026-10-26', $report['rows'][0]['date']);
        $this->assertSame('Monday', $report['rows'][0]['weekday']);
        $this->assertSame('2026-11-08', $report['rows'][13]['date']);
    }

    public function test_actual_uses_paid_orders_in_malaysia_time_net_of_refunds(): void
    {
        $this->sale(100, '2026-11-07 15:59');                                  // Sat 7 Nov 23:59 in KL: RM 110
        $this->sale(200, '2026-11-07 16:00', ['refunded_amount' => 60]);       // Sun 8 Nov 00:00 in KL: RM 210 - 60
        $this->sale(300, null);                                                // not paid
        $this->sale(400, '2026-11-08 16:30');                                  // Mon 9 Nov, today: outside the window

        $report = app(ForecastAccuracy::class)->report();

        $this->assertSame('110.00', $this->rowFor($report, '2026-11-07')['actual']);
        $this->assertSame('150.00', $this->rowFor($report, '2026-11-08')['actual']);
    }

    public function test_difference_percent_mae_and_wape(): void
    {
        $this->sale(190, '2026-11-02 03:00'); // actual RM 200
        $this->sale(390, '2026-11-03 03:00'); // actual RM 400
        $this->forecast('2026-11-02', 250);   // difference -50
        $this->forecast('2026-11-03', 300);   // difference +100
        $this->forecast('2026-11-04', 75);    // no sales: difference -75

        $report = app(ForecastAccuracy::class)->report();

        $low = $this->rowFor($report, '2026-11-02');
        $this->assertSame(['250.00', '200.00', '-50.00', -25.0], [$low['forecast'], $low['actual'], $low['difference'], $low['percent']]);
        $high = $this->rowFor($report, '2026-11-03');
        $this->assertSame(['100.00', 25.0], [$high['difference'], $high['percent']]);
        // No sales: the % can't be computed, but the day still counts.
        $none = $this->rowFor($report, '2026-11-04');
        $this->assertSame(['0.00', '-75.00', null], [$none['actual'], $none['difference'], $none['percent']]);

        // MAE = (50 + 100 + 75) / 3 = 75. WAPE = 225 / 600 = 37.5%. Days without a forecast don't count.
        $this->assertSame(['days' => 3, 'mae' => '75.00', 'wape' => 37.5], $report['saved']);
        $this->assertSame(['days' => 0, 'mae' => null, 'wape' => null], $report['backfilled']);
    }

    public function test_backfilled_days_are_measured_separately(): void
    {
        $this->sale(190, '2026-11-02 03:00');
        $this->sale(190, '2026-11-03 03:00');
        $this->forecast('2026-11-02', 300);
        $this->forecast('2026-11-03', 100, backfill: true);

        $report = app(ForecastAccuracy::class)->report();

        $this->assertSame(['days' => 1, 'mae' => '100.00', 'wape' => 50.0], $report['saved']);
        $this->assertSame(['days' => 1, 'mae' => '100.00', 'wape' => 50.0], $report['backfilled']);
        $this->assertTrue($this->rowFor($report, '2026-11-03')['backfilled']);
        $this->assertFalse($this->rowFor($report, '2026-11-02')['backfilled']);
    }

    public function test_which_forecast_is_used_for_a_day(): void
    {
        // A saved forecast beats a more recent backfilled one.
        $this->forecast('2026-11-02', 111, madeOn: '2026-10-28');
        $this->forecast('2026-11-02', 999, backfill: true, madeOn: '2026-11-01');
        // The most recent one made before the day wins.
        $this->forecast('2026-11-03', 222, madeOn: '2026-10-30');
        $this->forecast('2026-11-03', 333, madeOn: '2026-11-02');
        // One made on the day itself, or later, doesn't count.
        $this->forecast('2026-11-04', 444, madeOn: '2026-11-03');
        $this->forecast('2026-11-04', 555, madeOn: '2026-11-04');
        $this->forecast('2026-11-05', 666, madeOn: '2026-11-05');

        $report = app(ForecastAccuracy::class)->report();

        $this->assertSame('111.00', $this->rowFor($report, '2026-11-02')['forecast']);
        $this->assertSame('333.00', $this->rowFor($report, '2026-11-03')['forecast']);
        $this->assertSame('444.00', $this->rowFor($report, '2026-11-04')['forecast']);
        $this->assertNull($this->rowFor($report, '2026-11-05')['forecast']);
    }

    // --- page -------------------------------------------------------------------------------------

    public function test_the_page_shows_the_table_and_summary_even_when_the_api_is_offline(): void
    {
        Http::fake(fn () => Http::failedConnection('Connection refused'));
        $this->sale(190, '2026-11-02 03:00');
        $this->sale(190, '2026-11-03 03:00');
        $this->forecast('2026-11-02', 250);
        $this->forecast('2026-11-03', 150, backfill: true);

        $response = $this->actingAs($this->admin)->get(route('admin.insights.forecast'))->assertOk();

        $response->assertSee('ML insights are offline')
            ->assertSee('data-forecast-accuracy', false)
            ->assertSee('Forecast vs actual')
            ->assertSeeText(['26 Oct 2026 – 8 Nov 2026', 'RM 250.00', 'RM 200.00', '−RM 50.00', '−25.0%', '+RM 50.00', '+25.0%', 'MAE', 'WAPE', 'Backfilled (reference only)', 'No forecast saved'])
            ->assertSee('data-backfilled', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-backfilled'));
    }

    public function test_the_page_says_so_when_nothing_has_been_compared_yet(): void
    {
        Http::fake(fn () => Http::failedConnection('Connection refused'));

        $this->actingAs($this->admin)->get(route('admin.insights.forecast'))
            ->assertOk()
            ->assertSee('No days to compare yet.')
            ->assertSee('No forecast saved');
    }
}
