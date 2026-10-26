<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Now" is Wednesday 28 Oct 2026, 10:00 in Kuala Lumpur (02:00 UTC).
 */
class DashboardTest extends TestCase
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

    private function dashboard()
    {
        return $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_revenue_cards_compare_with_the_same_time_one_period_earlier(): void
    {
        $this->sale(190, '2026-10-28 01:00'); // today 09:00 KL: 200
        $this->sale(90, '2026-10-27 01:00');  // yesterday 09:00 KL: 100 (before "now" yesterday)
        $this->sale(490, '2026-10-27 05:00'); // yesterday 13:00 KL: after "now" yesterday, not compared
        $this->sale(40, '2026-10-01 03:00');  // earlier this month: 50
        $this->sale(1000, null, ['status' => OrderStatus::Shipped, 'payment_method' => PaymentMethod::CashOnDelivery]); // not collected

        $cards = collect($this->dashboard()->viewData('cards'))->keyBy('label');

        $this->assertSame('200.00', $cards['Today']['summary']['net']);
        $this->assertSame(1, $cards['Today']['summary']['orders']);
        $this->assertSame(100, $cards['Today']['change'], 'RM 200 vs RM 100 by 10 am yesterday = +100%.');
        $this->assertSame('800.00', $cards['This week']['summary']['net']);
        $this->assertSame('850.00', $cards['This month']['summary']['net']);
        $this->assertNull($cards['This month']['change'], 'No sales last month: no percentage.');

        $this->dashboard()->assertSee(['RM 200.00', '+100% vs yesterday', 'RM 850.00']);
    }

    public function test_sales_chart_covers_the_last_30_days(): void
    {
        $this->sale(90, '2026-10-20 03:00');
        $this->sale(190, '2026-09-29 03:00'); // first day of the 30-day window (29 Sep)
        $this->sale(990, '2026-09-28 03:00'); // outside the window

        $response = $this->dashboard();
        $chart = $response->viewData('chart');

        $this->assertCount(30, $chart);
        $this->assertSame('2026-09-29', array_key_first($chart));
        $this->assertSame('2026-10-28', array_key_last($chart));
        $this->assertSame('100.00', $chart['2026-10-20']['net']);
        $this->assertSame(30, substr_count($response->getContent(), 'data-bar'));
        $response->assertSee('RM 300.00 net from 2 paid orders');
    }

    public function test_order_status_overview_counts_and_links(): void
    {
        $this->sale(100, null, ['status' => OrderStatus::Pending]);
        $this->sale(100, null, ['status' => OrderStatus::Pending]);
        $this->sale(100, '2026-10-20 03:00', ['refunded_amount' => 20]);
        $this->sale(100, null, ['status' => OrderStatus::Cancelled, 'payment_method' => PaymentMethod::CashOnDelivery, 'delivery_failed_at' => now()]);

        $counts = collect($this->dashboard()->viewData('statusCounts'))->pluck('count', 'label');

        $this->assertSame(2, $counts['Pending']);
        $this->assertSame(1, $counts['Completed']);
        $this->assertSame(1, $counts['Partially refunded']);
        $this->assertSame(1, $counts['Failed delivery (COD)']);
        $this->dashboard()->assertSee(route('admin.orders.index', ['status' => 'pending']), false);
    }

    public function test_top_selling_products_brands_and_categories(): void
    {
        $samba = $this->product('Adidas Samba OG', 'Adidas', 'Sneakers', 150);
        $dunk = $this->product('Nike Dunk Low', 'Nike', 'Sneakers', 300);
        $this->sale(150, '2026-10-20 03:00', product: $samba, quantity: 2);
        $this->sale(300, '2026-10-21 03:00', product: $dunk);
        $this->sale(999, '2026-09-01 03:00', product: $this->product('Old Sale', 'Vans', 'Casual')); // older than 30 days

        $this->dashboard()
            ->assertSeeInOrder(['Top selling products', 'Adidas Samba OG', 'Nike Dunk Low'])
            ->assertSee(['Adidas (2)', 'Sneakers (3)'])
            ->assertDontSee('Old Sale');
    }

    public function test_low_stock_shows_only_multi_pair_products_running_low(): void
    {
        $runningLow = $this->product('Adidas Samba OG', 'Adidas', 'Sneakers', 150, stock: 2);
        $this->sale(150, '2026-10-20 03:00', product: $runningLow); // started with 3
        $this->product('Nike Dunk Low', 'Nike', 'Sneakers', 300, stock: 1);       // one of a kind
        $this->product('Vans Old Skool', 'Vans', 'Casual', 120, stock: 2);         // listed with 2, none sold
        $cancelledOnly = $this->product('Asics Gel-Kayano', 'Asics', 'Running', 200, stock: 1);
        $this->sale(200, null, ['status' => OrderStatus::Cancelled], product: $cancelledOnly); // cancelled sales don't count
        $this->product('Puma Suede', 'Puma', 'Casual', 90, stock: 0)->update(['status' => ProductStatus::Available]);

        $response = $this->dashboard();

        $this->assertSame(['Adidas Samba OG'], $response->viewData('lowStock')->pluck('name')->all());
        $this->assertSame(['Puma Suede'], $response->viewData('outOfStock')->pluck('name')->all());
        $response->assertSee(['2 left', 'Listed as available but out of stock']);
    }

    public function test_recent_orders_newest_first(): void
    {
        $this->sale(100, null, ['order_number' => 'TS-20261001-OLDEST', 'created_at' => now()->subDays(5)]);
        $this->sale(100, null, ['order_number' => 'TS-20261028-NEWEST', 'created_at' => now()]);

        $this->dashboard()->assertSeeInOrder(['Recent orders', 'TS-20261028-NEWEST', 'TS-20261001-OLDEST']);
    }

    public function test_empty_shop_dashboard_still_renders(): void
    {
        $this->dashboard()->assertSee(['RM 0.00', 'No sales in the last 30 days.', 'Nothing running low.', 'No orders yet.']);
    }
}
