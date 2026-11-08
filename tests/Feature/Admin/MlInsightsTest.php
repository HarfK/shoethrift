<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\InsightsController;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin Insights pages, price suggestion and dashboard cards, with the ML API faked (Http::fake()).
 */
class MlInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ml.url' => 'http://ml.test', 'services.ml.key' => 'test-ml-key']);
        $this->admin = User::factory()->admin()->create();
    }

    // --- fake API data -----------------------------------------------------------------------------

    private function forecastJson(): array
    {
        // Day 3 = 11.11 (one day), days 10-13 = Black Friday (4 days), day 20 = payday, days 22-24 = pre-Raya.
        $events = [3 => ['11.11 shopping day', '11.11', true]];
        foreach (range(10, 13) as $i) {
            $events[$i] = ['Black Friday', 'black_friday', true];
        }
        $events[20] = ['Payday', 'payday', false];
        foreach (range(22, 24) as $i) {
            $events[$i] = ['Pre-Raya', 'pre_raya', true];
        }

        $days = [];
        foreach (range(0, 29) as $i) {
            [$event, $key, $major] = $events[$i + 1] ?? [null, null, false];
            $days[] = ['date' => now()->addDays($i + 1)->toDateString(), 'weekday' => now()->addDays($i + 1)->englishDayOfWeek,
                'forecast' => $key === '11.11' ? 1774.29 : 800.0, 'lower' => 200.0, 'upper' => $key === '11.11' ? 2872.22 : 1900.0,
                'event' => $event, 'event_key' => $key, 'major' => $major, 'uplift' => $key === '11.11' ? 2.02 : null];
        }
        $actual = [];
        foreach (range(29, 0) as $i) {
            $actual[] = ['date' => now()->subDays($i)->toDateString(), 'weekday' => now()->subDays($i)->englishDayOfWeek, 'revenue' => $i === 5 ? 0.0 : 700.0,
                'partial_day' => $i === 0, 'event' => null, 'event_key' => null, 'major' => false];
        }

        return ['today' => now()->toDateString(), 'model' => 'XGBoost + major-event uplift', 'model_trained_until' => '2026-11-07',
            'days' => $days, 'total_forecast' => 0, 'next_7_days' => ['from' => $days[0]['date'], 'to' => $days[6]['date'], 'total' => 6878.0, 'lower' => 5055.0, 'upper' => 12186.0],
            'last_30_days_actual' => $actual, 'note' => ''];
    }

    private function group(string $name, string $band, string $risk, int $stock, int $qty, string $sizes, string $source = 'model', ?float $cover = 0.0): array
    {
        return ['group' => "$name · $band", 'as_of' => '2026-11-08', 'name' => $name, 'brand' => strtok($name, ' '), 'category' => 'Casual',
            'size_band' => $band, 'stock' => $stock, 'model_demand_14d' => 2.9, 'predicted_demand_14d' => 4.24, 'demand_source' => $source,
            'upper_demand_14d' => 6.0, 'sold_last_28d' => 4, 'suggested_restock_qty' => $qty, 'days_of_cover' => $cover, 'risk' => $risk, 'sizes_to_look_for' => $sizes];
    }

    private function restockJson(): array
    {
        $groups = [
            // As the API sends it: most popular size first, with its share.
            $this->group('Adidas Samba OG', 'S', 'HIGH', 0, 6, 'UK 4 x2 (22%), UK 5.5 x1 (20%), UK 3 x1 (20%), UK 5 x1 (17%), UK 3.5 x1 (8%)', 'in-stock rate'),
            $this->group('Adidas Samba OG', 'M', 'MEDIUM', 1, 2, 'UK 8 x1 (24%), UK 6.5 x1 (13%)'),
            $this->group('Converse Chuck 70', 'S', 'HIGH', 0, 3, 'UK 5.5 x1 (30%), UK 4 x1 (20%), UK 4.5 x1 (20%)'),
            $this->group('Clarks Desert Boot', 'L', 'MEDIUM', 1, 1, 'UK 10 x1 (41%)'),
            $this->group('Nike Dunk Low', 'M', 'LOW', 4, 0, '', 'model', 30.0),
            $this->group('Puma RS-X', 'L', 'OVERSTOCK', 6, 0, '', 'model', 240.0),
        ];

        return ['as_of' => '2026-11-08', 'data_until' => '2026-11-07', 'model' => 'Random forest',
            'summary' => ['groups' => 3, 'by_risk' => ['HIGH' => 1, 'MEDIUM' => 0, 'LOW' => 1, 'OVERSTOCK' => 1], 'total_pairs_suggested' => 6,
                'groups_to_restock' => 1, 'groups_using_in_stock_rate' => 1],
            'filter' => null, 'shown' => ['groups' => 3, 'pairs_suggested' => 6], 'groups' => $groups,
            'brands' => [['brand' => 'Adidas', 'as_of' => '2026-11-08', 'stock' => 20, 'predicted_demand_14d' => 14.3, 'groups_corrected' => 2,
                'sold_last_28d' => 18, 'groups' => 9, 'suggested_restock_qty' => 12, 'upper_demand_14d' => 17, 'days_of_cover' => 19.6, 'risk' => 'LOW']],
            'note' => ''];
    }

    private function healthJson(): array
    {
        $model = fn (string $name, string $metric, float $value) => ['available' => true, 'file' => 'x.joblib', 'error' => null, 'name' => $name,
            'trained_until' => '2026-11-07', 'key_metric' => ['name' => $metric, 'value' => $value]];

        return ['status' => 'ok', 'today_malaysia' => '2026-11-08', 'models' => [
            'revenue' => $model('XGBoost + major-event uplift', 'MAE per day, test window A (RM)', 587.16),
            'restock' => $model('Random forest', 'sell-out F1 (13-week backtest)', 0.6124),
            'price' => $model('Random forest (ratio)', 'MAE on later sales (RM)', 28.726),
        ], 'cache' => [['key' => 'forecast/2026-11-08', 'age_seconds' => 120, 'expires_in_seconds' => 3480]],
            'retrain' => ['status' => 'idle', 'started_at' => null, 'finished_at' => null, 'summary' => null, 'error' => null]];
    }

    private function priceJson(bool $unseen = false): array
    {
        return ['suggested_price' => $unseen ? 355 : 615, 'range_80' => ['lower' => 295, 'upper' => 415], 'model_estimate' => $unseen ? 352.91 : 615.86,
            'reasons' => ['Retail RM599 x typical resale ratio 51% = RM304', 'Like new condition: +RM110', 'Other details: +RM2'],
            'breakdown' => [['label' => 'Retail RM599 x typical resale ratio 51% = RM304', 'rm' => 304.17], ['label' => 'Like new condition: +RM110', 'rm' => 110.0]],
            'above_retail' => ! $unseen, 'unseen_model' => $unseen,
            'warnings' => $unseen ? ['New model with no sales history - price is less certain (typical error about RM60).'] : ['Above original retail price - hyped model, check before listing.'],
            'notes' => [], 'retail_price_used' => 599.0, 'retail_price_estimated' => ! $unseen, 'missing_details' => ['material'],
            'hint' => 'Fill in material, sole condition and box for a more accurate price.', 'model' => 'Random forest (ratio)'];
    }

    private function fakeApi(array $overrides = []): void
    {
        Http::fake([
            'ml.test/health' => Http::response($this->healthJson()),
            'ml.test/forecast/revenue*' => Http::response($this->forecastJson()),
            'ml.test/restock/report*' => Http::response($this->restockJson()),
            'ml.test/price/recommend' => Http::response($this->priceJson()),
            'ml.test/admin/retrain' => Http::response(['status' => 'started', 'message' => 'Retraining'], 202),
            ...$overrides,
        ]);
    }

    private function fakeOffline(): void
    {
        Http::fake(fn () => Http::failedConnection('Connection refused'));
    }

    // --- Insights pages ------------------------------------------------------------------------------

    public function test_forecast_page_shows_chart_cards_events_and_table(): void
    {
        $this->fakeApi();

        $this->actingAs($this->admin)->get(route('admin.insights.forecast'))
            ->assertOk()
            ->assertSee(['Revenue forecast', 'Next 7 days (forecast)', 'RM 6,878.00', 'Last 7 days (actual)', 'RM 4,200.00', '11.11 shopping day', '×2.02'])
            ->assertSee('data-forecast-chart', false)
            ->assertSee('data-forecast-band', false)
            ->assertSee('Model trained until 7 Nov 2026');

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-API-Key', 'test-ml-key') && str_contains($r->url(), '/forecast/revenue'));
    }

    public function test_chart_labels_major_events_once_and_shades_payday(): void
    {
        $this->fakeApi();

        $html = $this->actingAs($this->admin)->get(route('admin.insights.forecast', ['days' => 30]))->assertOk()->getContent();
        $chart = substr($html, strpos($html, 'data-forecast-chart'), strpos($html, 'data-chart-legend') - strpos($html, 'data-forecast-chart'));

        // One pill per event: 11.11 (1 day), Black Friday (4 days), Pre-Raya (3 days); none for payday.
        $this->assertSame(3, substr_count($chart, 'data-event-label'));
        $this->assertSame(1, substr_count($chart, '>Black Friday</text>'));
        $this->assertStringNotContainsString('>Payday</text>', $chart);
        $this->assertGreaterThan(0, substr_count($chart, 'data-payday-band'));
        $this->assertSame(1, substr_count($chart, 'data-preraya-band'));
        $this->assertStringContainsString('data-today-line', $chart);
        $this->assertSame(60, substr_count($chart, 'data-tip=')); // a hover tooltip on every day: 30 actual + 30 forecast
        $this->assertStringContainsString('RM 3k', $chart); // nice max just above the highest upper bound (RM 2,872)
        $this->assertStringContainsString('Payday period', $html);
        $this->assertStringContainsString('Pre-Raya period', $html);
    }

    public function test_forecast_toggle_shows_7_or_30_days(): void
    {
        $this->fakeApi();

        $seven = $this->actingAs($this->admin)->get(route('admin.insights.forecast'))->viewData('forecast');
        $thirty = $this->actingAs($this->admin)->get(route('admin.insights.forecast', ['days' => 30]))->viewData('forecast');
        $this->assertCount(7, $seven);
        $this->assertCount(30, $thirty);
    }

    public function test_restock_page_shows_cards_table_overstock_and_brands(): void
    {
        $this->fakeApi();

        $response = $this->actingAs($this->admin)->get(route('admin.insights.restock'))
            ->assertOk()
            ->assertSee(['Pairs to restock', 'Adidas Samba OG', 'UK 3 ×1', 'In-stock rate', 'fewer than 2 pairs', 'Overstock', 'Puma RS-X', 'Large · UK 9–12', 'Suggest −15%', 'By brand'])
            ->assertDontSee(['size band S', 'S = UK 3–5.5'])
            ->assertSee('data-in-stock-rate', false);

        $link = route('admin.products.index', ['search' => 'Puma RS-X', 'size_min' => 9.0, 'size_max' => 12.0, 'status' => 'available']);
        $response->assertSee($link); // escaped (&amp;) in the HTML
    }

    public function test_restock_risk_filter(): void
    {
        $this->fakeApi();

        $groups = $this->actingAs($this->admin)->get(route('admin.insights.restock', ['risk' => 'HIGH']))->viewData('groups');
        $this->assertSame(['HIGH'], $groups->pluck('risk')->unique()->values()->all());
        $this->actingAs($this->admin)->get(route('admin.insights.restock', ['risk' => 'nonsense']))->assertOk(); // ignored, shows all
    }

    public function test_restock_sizes_are_chips_sorted_by_size_with_priorities_and_share_tooltips(): void
    {
        $this->fakeApi();

        $response = $this->actingAs($this->admin)->get(route('admin.insights.restock'))->assertOk();
        $samba = $response->viewData('groups')->firstWhere('group', 'Adidas Samba OG · S');

        // Ascending by size (3, 3.5, 4, 5, 5.5), not by share; the ML text itself is unchanged.
        $this->assertSame(['3', '3.5', '4', '5', '5.5'], array_column($samba['sizes'], 'size_label'));
        $this->assertSame('UK 4 x2 (22%), UK 5.5 x1 (20%), UK 3 x1 (20%), UK 5 x1 (17%), UK 3.5 x1 (8%)', $samba['sizes_to_look_for']);
        // Priority: 2+ pairs (UK 4, also the top share). Nothing else here.
        $this->assertSame(['4'], array_column(array_filter($samba['sizes'], fn ($s) => $s['priority']), 'size_label'));
        $this->assertSame('Small · UK 3–5.5', $samba['band_label']);

        $response->assertSeeInOrder(['UK 3 ×1', 'UK 3.5 ×1', 'UK 4 ×2', 'UK 5 ×1', 'UK 5.5 ×1'])
            ->assertSee('title="22% of this band&#039;s recent sales (last 180 days) · priority"', false)
            ->assertSee('title="8% of this band&#039;s recent sales (last 180 days)"', false)
            ->assertSee('data-band-badge>Medium · UK 6–8.5', false);
        $this->assertMatchesRegularExpression('/data-size-chip="4"\s+data-priority/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-size-chip="3"\s+data-priority/', $response->getContent());
    }

    public function test_parse_sizes(): void
    {
        $sizes = InsightsController::parseSizes('UK 10 x1 (30%), UK 9.5 x1 (30%), UK 12 x2 (10%), UK 9 x1 (5%)');

        $this->assertSame([9.0, 9.5, 10.0, 12.0], array_column($sizes, 'size'));
        // Ties for the top share are both priorities; 2+ pairs is a priority whatever its share.
        $this->assertSame(['9.5', '10', '12'], array_column(array_values(array_filter($sizes, fn ($s) => $s['priority'])), 'size_label'));
        $this->assertSame([], InsightsController::parseSizes(''));
        $this->assertSame([null], array_column(InsightsController::parseSizes('UK 7 x1'), 'share'));
        $this->assertSame('Large · UK 9–12', InsightsController::bandLabel('L'));
    }

    public function test_restock_shopping_list_view(): void
    {
        $this->fakeApi();

        $response = $this->actingAs($this->admin)->get(route('admin.insights.restock', ['view' => 'list']))->assertOk();
        $list = $response->viewData('shoppingList');

        // Only groups with pairs to buy, per model; High first, then most pairs.
        $this->assertSame(['Adidas Samba OG', 'Converse Chuck 70', 'Clarks Desert Boot'], array_column($list, 'name'));
        $this->assertSame(['HIGH', 'HIGH', 'MEDIUM'], array_column($list, 'risk'));
        $this->assertSame([8, 3, 1], array_column($list, 'total'));
        $this->assertSame(['S', 'M'], array_column($list[0]['bands'], 'band'));
        $this->assertSame(['6.5', '8'], array_column($list[0]['bands'][1]['sizes'], 'size_label'));

        $response->assertSee('data-shopping-list', false)
            ->assertDontSee('data-restock-table', false)
            ->assertSeeText(['Adidas · Casual', '8 pairs to find', '12 pairs to find', 'Small · UK 3–5.5', 'Medium · UK 6–8.5'])
            ->assertSee('<input type="checkbox"', false)
            ->assertSee('onclick="window.print()"', false)
            ->assertSee(route('admin.insights.restock.csv'))
            ->assertDontSee('Nike Dunk Low')   // restock 0
            ->assertDontSee('data-model="Puma RS-X"', false);
        // The toggle links keep the view; the default view is the table.
        $this->actingAs($this->admin)->get(route('admin.insights.restock'))->assertSee('data-restock-table', false)->assertDontSee('data-shopping-list', false);
    }

    public function test_risk_filters_work_in_both_views(): void
    {
        $this->fakeApi();

        $high = $this->actingAs($this->admin)->get(route('admin.insights.restock', ['view' => 'list', 'risk' => 'high']));
        $this->assertSame(['Adidas Samba OG', 'Converse Chuck 70'], array_column($high->viewData('shoppingList'), 'name'));
        $this->assertSame([6, 3], array_column($high->viewData('shoppingList'), 'total')); // only the HIGH band of the Samba
        $high->assertSee(route('admin.insights.restock', ['view' => 'list', 'risk' => 'MEDIUM']))  // risk links keep the view
            ->assertSee(route('admin.insights.restock', ['risk' => 'HIGH']))                      // view toggle keeps the risk
            ->assertSee(route('admin.insights.restock.csv', ['risk' => 'HIGH']));

        $this->assertSame([], $this->actingAs($this->admin)->get(route('admin.insights.restock', ['view' => 'list', 'risk' => 'OVERSTOCK']))
            ->assertSee('Nothing to restock at this risk level.')->viewData('shoppingList'));
        $this->assertSame(['MEDIUM'], $this->actingAs($this->admin)->get(route('admin.insights.restock', ['risk' => 'MEDIUM']))
            ->viewData('groups')->pluck('risk')->unique()->values()->all());
        $this->actingAs($this->admin)->get(route('admin.insights.restock', ['view' => 'bogus']))->assertSee('data-restock-table', false);
    }

    public function test_restock_shopping_list_csv(): void
    {
        $this->fakeApi();

        $csv = $this->actingAs($this->admin)->get(route('admin.insights.restock.csv'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('shoethrift-shopping-list-2026-11-08.csv')
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($csv, 3)))));
        $this->assertSame(['Model', 'Brand', 'Size band', 'Size (UK)', 'Quantity', 'Risk'], $rows[0]);
        $this->assertSame(['Adidas Samba OG', 'Adidas', 'Small · UK 3–5.5', '3', '1', 'HIGH'], $rows[1]);
        $this->assertSame(['Adidas Samba OG', 'Adidas', 'Small · UK 3–5.5', '4', '2', 'HIGH'], $rows[3]);
        $this->assertSame(['Adidas Samba OG', 'Adidas', 'Medium · UK 6–8.5', '6.5', '1', 'MEDIUM'], $rows[6]);
        $this->assertSame(['Clarks Desert Boot', 'Clarks', 'Large · UK 9–12', '10', '1', 'MEDIUM'], end($rows));
        $this->assertCount(1 + 5 + 2 + 3 + 1, $rows);
        $this->assertSame(12, array_sum(array_map(fn ($r) => (int) $r[4], array_slice($rows, 1))));

        $medium = $this->actingAs($this->admin)->get(route('admin.insights.restock.csv', ['risk' => 'MEDIUM']))
            ->assertDownload('shoethrift-shopping-list-2026-11-08-medium.csv')->streamedContent();
        $this->assertStringNotContainsString('Converse', $medium);
        $this->assertStringContainsString('"Adidas Samba OG",Adidas,"Medium · UK 6–8.5",8,1,MEDIUM', $medium);
    }

    public function test_restock_csv_when_the_api_is_offline(): void
    {
        $this->fakeOffline();

        $this->actingAs($this->admin)->get(route('admin.insights.restock.csv', ['risk' => 'HIGH']))
            ->assertRedirect(route('admin.insights.restock', ['view' => 'list', 'risk' => 'HIGH']))
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->get(route('admin.insights.restock', ['view' => 'list']))->assertOk()->assertDontSee('Export CSV');
    }

    public function test_status_page_and_retrain(): void
    {
        $this->fakeApi();

        $this->actingAs($this->admin)->get(route('admin.insights.status'))
            ->assertOk()
            ->assertSee(['API online', 'XGBoost + major-event uplift', 'Random forest (ratio)', '7 Nov 2026', '0.612', 'Retrain now', 'forecast/2026-11-08']);

        $this->actingAs($this->admin)->from(route('admin.insights.status'))->post(route('admin.insights.retrain'))
            ->assertRedirect(route('admin.insights.status'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Retraining started'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/admin/retrain'));
    }

    public function test_retrain_already_running_shows_an_error(): void
    {
        $this->fakeApi(['ml.test/admin/retrain' => Http::response(['error' => 'A retrain is already running.', 'details' => []], 409)]);

        $this->actingAs($this->admin)->post(route('admin.insights.retrain'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already running'));
    }

    public function test_pages_render_offline_without_errors(): void
    {
        $this->fakeOffline();

        foreach (['admin.insights.forecast', 'admin.insights.restock', 'admin.insights.status'] as $route) {
            $this->actingAs($this->admin)->get(route($route))
                ->assertOk()
                ->assertSee('ML insights are offline')
                ->assertSee('data-ml-offline', false);
        }
        $this->actingAs($this->admin)->get(route('admin.insights.status'))->assertSee('API offline');
        $this->actingAs($this->admin)->post(route('admin.insights.retrain'))->assertSessionHas('error');
    }

    public function test_not_configured_counts_as_offline_and_sends_nothing(): void
    {
        config(['services.ml.key' => null]);
        Http::fake();

        $this->actingAs($this->admin)->get(route('admin.insights.forecast'))->assertOk()->assertSee('not configured');
        Http::assertNothingSent();
    }

    public function test_sidebar_has_insights_section(): void
    {
        $this->fakeOffline();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertSee(['Insights', 'Revenue forecast', 'Restock', 'ML status'])
            ->assertSee('data-insights-link', false);
    }

    // --- Price suggestion ----------------------------------------------------------------------------

    private function formValues(array $overrides = []): array
    {
        $category = Category::factory()->create(['name' => 'Casual']);

        return ['name' => 'Adidas Samba OG', 'brand' => 'Adidas', 'category_id' => $category->id, 'condition' => 'like_new',
            'sole_condition' => 'excellent', 'box_included' => true, 'size' => 7.5, 'color' => 'Black/White', 'material' => 'Leather',
            'retail_price' => null, ...$overrides];
    }

    public function test_suggest_price_returns_the_breakdown(): void
    {
        $this->fakeApi();

        $this->actingAs($this->admin)->postJson(route('admin.products.suggest-price'), $this->formValues())
            ->assertOk()
            ->assertJsonPath('suggested_price', 615)
            ->assertJsonPath('above_retail', true)
            ->assertJsonPath('breakdown.0.label', 'Retail RM599 x typical resale ratio 51% = RM304')
            ->assertJsonCount(1, 'warnings')
            ->assertJsonPath('retail_price_estimated', true)
            ->assertJsonPath('hint', 'Fill in material, sole condition and box for a more accurate price.');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/price/recommend')
            && $r['category'] === 'Casual' && $r['box_included'] === true && $r['retail_price'] === null && $r['size'] === 7.5);
    }

    public function test_suggest_price_passes_api_validation_errors_through_in_plain_words(): void
    {
        $this->fakeApi(['ml.test/price/recommend' => Http::response(['error' => 'Invalid input.',
            'details' => ['size: must be between 3.0 and 12.0 in steps of 0.5 (UK size)', "brand: 'Gucci' is not a known brand; use one of: Adidas, Nike"]], 422)]);

        $this->actingAs($this->admin)->postJson(route('admin.products.suggest-price'), $this->formValues(['size' => 13, 'brand' => 'Gucci']))
            ->assertStatus(422)
            ->assertJsonPath('errors.0', 'Size (UK): must be between 3.0 and 12.0 in steps of 0.5 (UK size)')
            ->assertJsonPath('errors.1', "Brand: 'Gucci' is not a known brand; use one of: Adidas, Nike");
    }

    public function test_suggest_price_checks_required_fields_before_calling_the_api(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->postJson(route('admin.products.suggest-price'), ['name' => 'Samba'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand', 'category_id', 'condition', 'size']);
        Http::assertNothingSent();
    }

    public function test_suggest_price_when_offline_returns_503_with_a_message(): void
    {
        $this->fakeOffline();

        $this->actingAs($this->admin)->postJson(route('admin.products.suggest-price'), $this->formValues())
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'offline'));
    }

    public function test_product_form_has_retail_price_and_suggest_button(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee(['Retail price (RM)', 'Suggest price'])
            ->assertSee('data-suggest-price', false)
            ->assertSee('retail_price_estimated', false) // the script fills an empty Retail price with the estimate
            ->assertSee('body.hint', false);
        Http::assertNothingSent(); // the API is only called when the button is pressed
    }

    public function test_retail_price_is_saved_with_the_product(): void
    {
        $product = Product::factory()->create(['retail_price' => null]);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), [
            'category_id' => $product->category_id, 'name' => $product->name, 'brand' => $product->brand, 'price' => 250,
            'retail_price' => 459, 'condition' => 'good', 'size' => 8, 'stock' => 1, 'status' => 'available', 'box_included' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(459, (float) $product->fresh()->retail_price);
    }

    // --- Access, dashboard, products filter, command -------------------------------------------------

    public function test_customers_get_403(): void
    {
        $this->fakeApi();
        $customer = User::factory()->create();

        foreach (['admin.insights.forecast', 'admin.insights.restock', 'admin.insights.status', 'admin.insights.restock.csv'] as $route) {
            $this->actingAs($customer)->get(route($route))->assertForbidden();
        }
        $this->actingAs($customer)->post(route('admin.insights.retrain'))->assertForbidden();
        $this->actingAs($customer)->postJson(route('admin.products.suggest-price'), [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_dashboard_shows_ml_cards(): void
    {
        $this->fakeApi();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(['Forecast next 7 days', 'RM 6,878', '1 group need restock (HIGH)'])
            ->assertSee(route('admin.insights.restock', ['risk' => 'HIGH']), false);
    }

    public function test_dashboard_still_loads_when_the_api_is_down(): void
    {
        $this->fakeOffline();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(['Dashboard', 'ML insights offline'])
            ->assertDontSee('Forecast next 7 days');
    }

    public function test_products_list_filters_by_size_range(): void
    {
        Product::factory()->create(['name' => 'Puma RS-X', 'size' => 9.5]);
        Product::factory()->create(['name' => 'Puma RS-X', 'size' => 7]);

        $products = $this->actingAs($this->admin)
            ->get(route('admin.products.index', ['search' => 'Puma RS-X', 'size_min' => 9, 'size_max' => 12]))
            ->assertOk()
            ->assertSee('data-size-filter', false)
            ->viewData('products');
        $this->assertSame([9.5], $products->pluck('size')->map(fn ($s) => (float) $s)->all());
    }

    public function test_ml_retrain_command(): void
    {
        $this->fakeApi();
        $this->artisan('ml:retrain')->expectsOutputToContain('Retraining started')->assertSuccessful();

        $this->fakeOffline();
        $this->artisan('ml:retrain')->expectsOutputToContain('could not be started')->assertFailed();
    }
}
