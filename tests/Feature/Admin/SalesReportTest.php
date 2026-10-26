<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What counts as revenue, Malaysia-time periods, and the reports page with its exports.
 * "Now" is Wednesday 28 Oct 2026, 10:00 in Kuala Lumpur (02:00 UTC); the week began Monday 26 Oct.
 */
class SalesReportTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-28 02:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
    }

    private function month(): SalesReport
    {
        return new SalesReport(ReportPeriod::month(ReportPeriod::now()));
    }

    // --- What counts ------------------------------------------------------

    public function test_only_paid_orders_count_and_refunds_are_subtracted(): void
    {
        $this->sale(100, '2026-10-20 03:00', ['status' => OrderStatus::Paid]);                       // 110 online paid
        $this->sale(200, '2026-10-20 03:00', ['status' => OrderStatus::Shipped]);                    // 210 online shipped
        $this->sale(300, '2026-10-21 03:00', ['payment_method' => PaymentMethod::CashOnDelivery]);   // 310 COD cash collected
        $this->sale(400, null, ['status' => OrderStatus::Shipped, 'payment_method' => PaymentMethod::CashOnDelivery]);   // COD not collected
        $this->sale(500, null, ['status' => OrderStatus::Delivered, 'payment_method' => PaymentMethod::CashOnDelivery]); // COD not collected
        $this->sale(600, null, ['status' => OrderStatus::Pending]);                                  // unpaid
        $this->sale(700, '2026-10-21 03:00', ['status' => OrderStatus::Cancelled]);                  // late payment, refund needed
        $this->sale(800, null, ['status' => OrderStatus::Failed]);
        $this->sale(90, '2026-10-22 03:00', ['refunded_amount' => 30]);                              // 100, partly refunded
        $this->sale(40, '2026-10-22 03:00', ['status' => OrderStatus::Refunded, 'refunded_amount' => 50]); // 50, fully refunded

        $this->assertSame([
            'orders' => 5,
            'gross' => '780.00',
            'items' => '730.00',
            'shipping' => '50.00',
            'refunds' => '80.00',
            'net' => '700.00',
            'average' => '140.00',
        ], $this->month()->summary());
    }

    public function test_days_weeks_and_months_follow_malaysia_time(): void
    {
        $this->sale(100, '2026-10-27 16:30'); // 28 Oct 00:30 in KL: today
        $this->sale(200, '2026-10-27 15:30'); // 27 Oct 23:30 in KL: yesterday
        $this->sale(300, '2026-10-25 15:59'); // Sun 25 Oct 23:59 in KL: last week
        $this->sale(400, '2026-09-30 16:30'); // 1 Oct 00:30 in KL: this month
        $this->sale(500, '2026-09-30 15:30'); // 30 Sep 23:30 in KL: last month

        $now = ReportPeriod::now();
        $net = fn (ReportPeriod $period) => (new SalesReport($period))->summary()['net'];

        $this->assertSame('110.00', $net(ReportPeriod::day($now)));
        $this->assertSame('210.00', $net(ReportPeriod::day($now->subDay())));
        $this->assertSame('320.00', $net(ReportPeriod::week($now)));
        $this->assertSame('1040.00', $net(ReportPeriod::month($now)));
        $this->assertSame('510.00', $net(ReportPeriod::month($now->subMonth())));
    }

    // --- Reports page -----------------------------------------------------

    public function test_report_defaults_to_this_month_with_breakdowns_that_add_up(): void
    {
        $samba = $this->product('Adidas Samba OG', 'Adidas', 'Sneakers', 150, 0);
        $dunk = $this->product('Nike Dunk Low', 'Nike', 'Sneakers', 300);
        $boot = $this->product('Dr. Martens 1460', 'Dr. Martens', 'Boots', 250);
        $this->sale(150, '2026-10-02 03:00', product: $samba, quantity: 2);
        $this->sale(300, '2026-10-05 03:00', product: $dunk);
        $this->sale(250, '2026-10-06 03:00', ['payment_method' => PaymentMethod::CashOnDelivery], product: $boot);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee(['October 2026', 'RM 880.00', 'Download PDF', 'Export CSV', 'Print'])
            ->assertSeeInOrder(['By product', 'Adidas Samba OG', 'Nike Dunk Low', 'Dr. Martens 1460']); // pairs first, then sales

        $response->assertViewHas('byCategory', fn ($rows) => $rows->pluck('sales', 'name')->all() === ['Sneakers' => '600.00', 'Boots' => '250.00']);
        $response->assertViewHas('byPaymentMethod', fn ($rows) => $rows->pluck('gross', 'name')->all() === [
            'Online payment (HitPay)' => '620.00',
            'Cash on Delivery' => '260.00',
        ]);
        $response->assertViewHas('summary', fn ($summary) => $summary['items'] === '850.00' && $summary['gross'] === '880.00');
        $response->assertViewHas('overTime', fn ($rows) => count($rows) === 31 && $rows['2026-10-05']['net'] === '310.00');
    }

    public function test_daily_weekly_and_custom_periods(): void
    {
        $this->sale(100, '2026-10-26 03:00'); // Mon 26 Oct
        $this->sale(200, '2026-10-28 01:00'); // Wed 28 Oct
        $this->sale(300, '2026-10-19 03:00'); // Mon 19 Oct, previous week

        $summary = fn (array $query) => $this->actingAs($this->admin)->get(route('admin.reports.index', $query))
            ->assertOk()->viewData('summary');

        $this->assertSame('210.00', $summary(['period' => 'day', 'date' => '2026-10-28'])['net']);
        $this->assertSame('320.00', $summary(['period' => 'week', 'date' => '2026-10-27'])['net']);
        $this->assertSame('630.00', $summary(['period' => 'custom', 'from' => '2026-10-19', 'to' => '2026-10-28'])['net']);
        $this->assertSame('0.00', $summary(['period' => 'month', 'month' => '2026-09'])['net']);

        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'week', 'date' => '2026-10-27']))
            ->assertSee('Week of 26 Oct to 1 Nov 2026');
    }

    public function test_long_ranges_are_grouped_by_month(): void
    {
        $this->sale(100, '2026-03-10 03:00');

        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-10-28']))
            ->assertSee('Sales by month')
            ->assertViewHas('overTime', fn ($rows) => array_keys($rows)[0] === '2026-01' && count($rows) === 10 && $rows['2026-03']['net'] === '110.00');
    }

    public function test_invalid_periods_are_rejected(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors(['to' => 'The end date must be on or after the start date.']);
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-01-01']))
            ->assertSessionHasErrors(['to' => 'Choose a range of at most 366 days.']);
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'custom']))
            ->assertSessionHasErrors(['from', 'to']);
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'year', 'date' => '28/10/2026']))
            ->assertSessionHasErrors(['period', 'date']);
    }

    public function test_csv_export(): void
    {
        $this->sale(150, '2026-10-02 03:00', product: $this->product('Adidas Samba OG', 'Adidas', 'Sneakers'));

        $response = $this->actingAs($this->admin)->get(route('admin.reports.csv', ['period' => 'month', 'month' => '2026-10']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('shoethrift-sales-2026-10.csv');

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"ShoeThrift sales report","October 2026"', $csv);
        $this->assertStringContainsString('"Net revenue",160.00', $csv);
        $this->assertStringContainsString('"Adidas Samba OG","UK ', $csv);
        $this->assertStringContainsString('Sneakers,1,150.00', $csv);
        $this->assertStringContainsString('2026-10-02,1,160.00', $csv);
    }

    public function test_pdf_export(): void
    {
        $this->sale(150, '2026-10-02 03:00');

        $response = $this->actingAs($this->admin)->get(route('admin.reports.pdf', ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-15']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('shoethrift-sales-2026-10-01_2026-10-15.pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_customers_cannot_see_reports(): void
    {
        $customer = User::factory()->create();

        foreach (['admin.reports.index', 'admin.reports.csv', 'admin.reports.pdf'] as $route) {
            $this->actingAs($customer)->get(route($route))->assertForbidden();
        }
    }

    public function test_report_period_value_object(): void
    {
        $period = ReportPeriod::custom(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame(31, $period->days());
        $this->assertSame('day', $period->grouping());
        $this->assertSame('2026-09-30 16:00:00', $period->start->utc()->format('Y-m-d H:i:s'), 'KL midnight is 16:00 UTC the day before.');
    }
}
