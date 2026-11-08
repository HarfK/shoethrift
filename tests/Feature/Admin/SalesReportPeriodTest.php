<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Reports\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Sales report's period filter: how each period resolves (Malaysia time), validation,
 * the subtitle, the picker's restored values, the presets, and exports using the same range.
 * "Now" is Friday 6 Nov 2026, 10:00 in Kuala Lumpur (02:00 UTC); that week began Monday 2 Nov.
 */
class SalesReportPeriodTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-06 02:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, array{array<string, string>, string, string, string}>
     */
    public static function periods(): array
    {
        return [
            'day' => [['period' => 'day', 'date' => '2026-11-06'], '2026-11-06', '2026-11-06', 'Fri 6 Nov 2026'],
            'week from a Monday' => [['period' => 'week', 'date' => '2026-11-02'], '2026-11-02', '2026-11-08', '2 Nov – 8 Nov 2026'],
            'week from a Sunday (belongs to the week before)' => [['period' => 'week', 'date' => '2026-11-01'], '2026-10-26', '2026-11-01', '26 Oct – 1 Nov 2026'],
            'week across a new year' => [['period' => 'week', 'date' => '2025-12-31'], '2025-12-29', '2026-01-04', '29 Dec 2025 – 4 Jan 2026'],
            'month' => [['period' => 'month', 'month' => '2026-02'], '2026-02-01', '2026-02-28', 'February 2026'],
            'leap February' => [['period' => 'month', 'month' => '2024-02'], '2024-02-01', '2024-02-29', 'February 2024'],
            'December' => [['period' => 'month', 'month' => '2025-12'], '2025-12-01', '2025-12-31', 'December 2025'],
            'default = this month' => [[], '2026-11-01', '2026-11-30', 'November 2026'],
            'custom' => [['period' => 'custom', 'from' => '2026-11-02', 'to' => '2026-11-06'], '2026-11-02', '2026-11-06', '2 Nov – 6 Nov 2026'],
            'custom one day' => [['period' => 'custom', 'from' => '2026-11-06', 'to' => '2026-11-06'], '2026-11-06', '2026-11-06', 'Fri 6 Nov 2026'],
            'custom 366 days (the maximum)' => [['period' => 'custom', 'from' => '2025-11-06', 'to' => '2026-11-06'], '2025-11-06', '2026-11-06', '6 Nov 2025 – 6 Nov 2026'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('periods')]
    public function test_each_period_resolves_to_whole_malaysian_days(array $query, string $start, string $end, string $label): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.reports.index', $query))->assertOk();
        $period = $response->viewData('report')->period;

        $this->assertSame($start.' 00:00:00', $period->start->format('Y-m-d H:i:s'));
        $this->assertSame($end.' 23:59:59', $period->end->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Kuala_Lumpur', $period->start->timezoneName);
        $this->assertSame($label, $period->label);
        $response->assertSee('data-report-range>'.$label.'</span>', false);
    }

    public function test_the_range_boundaries_use_paid_at_in_malaysia_time(): void
    {
        $this->sale(100, '2026-11-01 15:59'); // Sun 1 Nov 23:59 KL: the week before
        $this->sale(200, '2026-11-01 16:00'); // Mon 2 Nov 00:00 KL
        $this->sale(400, '2026-11-05 16:30'); // Fri 6 Nov 00:30 KL

        $net = fn (array $query) => $this->actingAs($this->admin)->get(route('admin.reports.index', $query))->viewData('summary')['net'];

        $this->assertSame('620.00', $net(['period' => 'week', 'date' => '2026-11-04']));
        $this->assertSame('410.00', $net(['period' => 'day', 'date' => '2026-11-06']));
        $this->assertSame('110.00', $net(['period' => 'week', 'date' => '2026-10-26']));
        $this->assertSame('730.00', $net(['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-06']));
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalid(): array
    {
        return [
            'from after to' => [['period' => 'custom', 'from' => '2026-11-05', 'to' => '2026-11-01'], 'to', 'The end date must be on or after the start date.'],
            '367 days' => [['period' => 'custom', 'from' => '2025-11-05', 'to' => '2026-11-06'], 'to', 'Choose a range of at most 366 days.'],
            'far too long' => [['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-01-01'], 'to', 'Choose a range of at most 366 days.'],
            'custom ending in the future' => [['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-07'], 'to', 'The end date can\'t be in the future.'],
            'custom wholly in the future' => [['period' => 'custom', 'from' => '2026-12-01', 'to' => '2026-12-05'], 'to', 'The end date can\'t be in the future.'],
            'future day' => [['period' => 'day', 'date' => '2026-11-07'], 'date', 'The date can\'t be in the future.'],
            'future week' => [['period' => 'week', 'date' => '2026-11-09'], 'date', 'The week can\'t be in the future.'],
            'future month' => [['period' => 'month', 'month' => '2026-12'], 'month', 'The month can\'t be in the future.'],
            'bad date format' => [['period' => 'day', 'date' => '06/11/2026'], 'date', 'The date field must match the format Y-m-d.'],
            'custom without dates' => [['period' => 'custom'], 'from', 'The from field is required when period is custom.'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalid')]
    public function test_invalid_ranges_are_rejected_with_a_clear_message(array $query, string $field, string $message): void
    {
        foreach (['admin.reports.index', 'admin.reports.pdf', 'admin.reports.csv'] as $route) {
            $this->actingAs($this->admin)->get(route($route, $query))->assertSessionHasErrors([$field => $message]);
        }
    }

    public function test_the_current_week_and_month_are_allowed_so_far(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'week', 'date' => '2026-11-06']))
            ->assertOk()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'month', 'month' => '2026-11']))
            ->assertOk()->assertSessionHasNoErrors();
    }

    public function test_the_error_is_shown_on_the_report_page(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.index'));
        $this->actingAs($this->admin)->followingRedirects()
            ->get(route('admin.reports.index', ['period' => 'custom', 'from' => '2025-01-01', 'to' => '2026-11-06']))
            ->assertOk()
            ->assertSee('Choose a range of at most 366 days.');
    }

    public function test_the_picker_restores_the_resolved_values(): void
    {
        // Any day of a week is stored as its Monday.
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'week', 'date' => '2026-11-04']))
            ->assertSee('<option value="week" selected>', false)
            ->assertSee('name="date" value="2026-11-02"', false)
            ->assertSee('data-today="2026-11-06"', false)
            ->assertSee('data-max-days="366"', false);

        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-15']))
            ->assertSee('name="from" value="2026-10-01"', false)
            ->assertSee('name="to" value="2026-10-15"', false)
            ->assertSee('name="date" value=""', false);

        $this->actingAs($this->admin)->get(route('admin.reports.index'))
            ->assertSee('<option value="month" selected>', false)
            ->assertSee('name="month" value="2026-11"', false);
    }

    public function test_print_pdf_and_csv_use_the_same_range(): void
    {
        $this->sale(150, '2026-11-03 03:00');
        $query = ['period' => 'week', 'date' => '2026-11-02'];

        // The page links the exports with the resolved range, even when it was asked for by a mid-week day.
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'week', 'date' => '2026-11-05']))
            ->assertSee(route('admin.reports.pdf', $query))
            ->assertSee(route('admin.reports.csv', $query))
            ->assertSee('onclick="window.print()"', false);

        $csv = $this->actingAs($this->admin)->get(route('admin.reports.csv', $query))
            ->assertDownload('shoethrift-sales-2026-11-02_2026-11-08.csv')
            ->streamedContent();
        $this->assertStringContainsString('"ShoeThrift sales report","2 Nov – 8 Nov 2026"', $csv);
        $this->assertStringContainsString('"Net revenue",160.00', $csv);

        $this->actingAs($this->admin)->get(route('admin.reports.pdf', ['period' => 'day', 'date' => '2026-11-03']))
            ->assertOk()->assertDownload('shoethrift-sales-2026-11-03.pdf');
    }

    public function test_quick_presets(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'day', 'date' => '2026-11-05']));

        $response->assertSee(route('admin.reports.index', ['period' => 'day', 'date' => '2026-11-06']))      // Today
            ->assertSee(route('admin.reports.index', ['period' => 'day', 'date' => '2026-11-05']))          // Yesterday
            ->assertSee(route('admin.reports.index', ['period' => 'custom', 'from' => '2026-10-31', 'to' => '2026-11-06'])) // Last 7 days
            ->assertSee(route('admin.reports.index', ['period' => 'month', 'month' => '2026-11']))          // This month
            ->assertSee(route('admin.reports.index', ['period' => 'month', 'month' => '2026-10']));         // Last month

        // "Yesterday" is the active preset here.
        $this->assertSame('Yesterday', collect($response->viewData('filters')['presets'])->filter(fn ($p) => $p['active'])->keys()->sole());
        $this->assertSame(['day', 'custom', 'month'], array_values(array_unique(array_map(
            fn ($p) => $p['query']['period'], $response->viewData('filters')['presets'],
        ))));
    }

    public function test_last_month_preset_on_the_31st(): void
    {
        $this->travelTo(Carbon::parse('2026-03-31 02:00:00', 'UTC'));

        $presets = $this->actingAs($this->admin)->get(route('admin.reports.index'))->viewData('filters')['presets'];
        $this->assertSame(['period' => 'month', 'month' => '2026-02'], $presets['Last month']['query']);
        $this->assertTrue($presets['This month']['active']);
    }

    public function test_range_label_helper(): void
    {
        $tz = ReportPeriod::timezone();
        $this->assertSame('2 Nov – 8 Nov 2026', ReportPeriod::rangeLabel(Carbon::parse('2026-11-02', $tz)->toImmutable(), Carbon::parse('2026-11-08', $tz)->toImmutable()));
    }
}
