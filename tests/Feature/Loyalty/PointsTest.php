<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\PointTransactionType as Type;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Loyalty\PointsException;
use App\Services\Loyalty\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Loyalty points (App\Services\Loyalty\PointsService, config/loyalty.php): 1 point per RM1 of items,
 * 100 points = RM5, min 100, at most 50% of the items, expiry after 12 months (FIFO).
 */
class PointsTest extends TestCase
{
    use RefreshDatabase;

    private PointsService $points;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-08 04:00:00', 'UTC'));
        $this->points = app(PointsService::class);
        $this->customer = User::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function give(int $points, ?User $user = null): void
    {
        $this->points->adjust($user ?? $this->customer, $points, 'Test points', $this->admin);
    }

    private function codOrder(float $itemsTotal, array $attributes = []): Order
    {
        $order = Order::factory()->for($this->customer)->cashOnDelivery()->status(OrderStatus::Pending)
            ->create(['total' => $itemsTotal + 10, 'shipping_fee' => 10, ...$attributes]);
        OrderItem::factory()->for($order)->create(['price' => $itemsTotal, 'quantity' => 1]);

        return $order;
    }

    private function complete(Order $order): Order
    {
        foreach ([OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Completed] as $status) {
            $order->transitionTo($status);
        }

        return $order->fresh();
    }

    private function checkout(Product $product, int $points, string $method = 'cod')
    {
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $product->id]);

        return $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah Rahman', 'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => number_format((float) $product->price + 10, 2, '.', ''), // before points
            'payment_method' => $method, 'points' => $points,
        ]);
    }

    // --- Earning --------------------------------------------------------------------------------------

    public function test_points_are_earned_only_when_the_order_is_completed(): void
    {
        $order = $this->codOrder(250);

        foreach ([OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
            $order->transitionTo($status);
            $this->assertSame(0, $this->points->balance($this->customer), "no points while {$status->value}");
        }
        $order->transitionTo(OrderStatus::Completed);

        $this->assertSame(250, $this->points->balance($this->customer));
        $earn = PointTransaction::where('type', Type::Earn)->sole();
        $this->assertSame($order->id, $earn->order_id);
        $this->assertTrue($earn->expires_at->equalTo(now()->addMonthsNoOverflow(12)));

        // Completing can't happen twice, and earnFor is idempotent anyway.
        $this->points->earnFor($order->fresh());
        $this->assertSame(1, PointTransaction::where('type', Type::Earn)->count());
    }

    public function test_no_points_on_shipping_and_amounts_round_down(): void
    {
        $order = $this->complete($this->codOrder(199.99)); // total RM209.99 incl. RM10 shipping

        $this->assertSame(199, $this->points->earnedFor($order));
    }

    // --- Redeeming at checkout ------------------------------------------------------------------------

    public function test_redeeming_lowers_the_total_and_records_the_discount(): void
    {
        $this->give(300);
        $pair = Product::factory()->create(['price' => 200, 'stock' => 1]);

        $this->checkout($pair, 200)->assertRedirect();

        $order = Order::sole();
        $this->assertSame([200, '10.00', '200.00', '200.00'], [$order->points_redeemed, $order->points_discount, $order->total, $order->subtotal]);
        $this->assertSame(100, $this->points->balance($this->customer));

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee(['Points discount (200 points)', '−RM 10.00']);
    }

    /**
     * @return array<string, array{int, int, float, string}>
     */
    public static function badRedemptions(): array
    {
        return [
            'not a block of 100' => [150, 500, 200, 'blocks of 100'],
            'more than the balance' => [400, 300, 200, 'only have 300 points'],
            'more than 50% of the items' => [1100, 2000, 100, 'at most 50% of the items'],
        ];
    }

    #[DataProvider('badRedemptions')]
    public function test_redeem_limits(int $points, int $balance, float $price, string $message): void
    {
        $this->give($balance);
        $pair = Product::factory()->create(['price' => $price, 'stock' => 1]);

        $this->checkout($pair, $points)->assertSessionHasErrors('points');
        $this->assertStringContainsString($message, session('errors')->first('points'));

        $this->assertSame(0, Order::count(), 'nothing is ordered');
        $this->assertSame($balance, $this->points->balance($this->customer), 'no points taken');
    }

    public function test_minimum_points_comes_from_config(): void
    {
        config(['loyalty.redeem_min_points' => 200]);
        $this->give(500);

        $this->assertSame('You can use points from 200 points.', $this->points->redeemError(100, 500, 300));
        $this->assertNull($this->points->redeemError(200, 500, 300));
    }

    public function test_exactly_half_of_the_items_is_allowed(): void
    {
        $this->give(1000);

        $this->assertSame(1000, $this->points->maxRedeemable(1000, 100)); // 1000 points = RM50 = 50% of RM100
        $this->assertNull($this->points->redeemError(1000, 1000, 100));
        $this->assertSame(0, $this->points->maxRedeemable(99, 500)); // below the minimum
    }

    public function test_concurrent_redemptions_cannot_overspend(): void
    {
        // Two checkouts racing: each redeem locks the user row and re-reads the balance, so the second loses.
        $this->give(500);
        $first = $this->codOrder(400);
        $second = $this->codOrder(400);

        $this->points->redeem($this->customer, $first, 300);
        try {
            $this->points->redeem($this->customer, $second, 300);
            $this->fail('The second redemption should have been refused.');
        } catch (PointsException $e) {
            $this->assertStringContainsString('only have 200 points', $e->getMessage());
        }

        $this->assertSame(200, $this->points->balance($this->customer));
        $this->assertGreaterThanOrEqual(0, PointTransaction::min('balance_after'));
    }

    public function test_a_failed_checkout_rolls_the_points_back(): void
    {
        $this->give(300);
        $pair = Product::factory()->create(['price' => 200, 'stock' => 1]);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $pair->id]);
        $pair->update(['price' => 250]); // price changed: PlaceOrder throws after the cart was priced

        $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah', 'phone' => '012-345 6789', 'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam',
            'state' => 'Selangor', 'expected_total' => '210.00', 'payment_method' => 'cod', 'points' => 200,
        ]);

        $this->assertSame(0, Order::count());
        $this->assertSame(300, $this->points->balance($this->customer));
    }

    // --- Payment amounts --------------------------------------------------------------------------------

    public function test_hitpay_is_asked_for_the_discounted_total(): void
    {
        Http::fake(fn (HttpRequest $r) => Http::response(['id' => 'pr-1', 'url' => 'https://checkout.sandbox.hit-pay.com/pay/1', 'status' => 'pending'], 201));
        $this->give(400);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);

        $this->checkout($pair, 400, 'online')->assertRedirect('https://checkout.sandbox.hit-pay.com/pay/1');

        $this->assertSame('290.00', Order::sole()->total); // 300 + 10 shipping - RM20
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['amount'] === '290.00');
    }

    public function test_cod_collects_the_discounted_total(): void
    {
        $this->give(200);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 200);

        $order = $this->complete(Order::sole());

        $this->assertSame('300.00', $order->total); // 300 + 10 - RM10: what the courier collects
        $this->assertNotNull($order->paid_at);
        // Points are earned on the item subtotal (RM300, before the points discount, shipping excluded).
        $this->assertSame(300, $this->points->earnedFor($order));
    }

    // --- Reversals ----------------------------------------------------------------------------------------

    public function test_a_full_refund_takes_the_earned_points_back(): void
    {
        $order = $this->complete($this->codOrder(300));
        $this->assertSame(300, $this->points->balance($this->customer));

        // What RecordRefund does when the whole order is refunded.
        $order->forceFill(['refunded_amount' => $order->total, 'status' => OrderStatus::Refunded])->save();

        $this->assertSame(0, $this->points->balance($this->customer));
        $this->assertSame(-300, (int) PointTransaction::where('type', Type::ReverseEarn)->sum('points'));
    }

    public function test_a_partial_refund_takes_back_a_proportional_share(): void
    {
        $order = $this->complete($this->codOrder(390)); // total RM400, earns 390

        $order->forceFill(['refunded_amount' => '200.00'])->save(); // half the money back, still Completed

        $this->assertSame(195, $this->points->reversedFor($order));
        $this->assertSame(195, $this->points->balance($this->customer));

        $order->forceFill(['refunded_amount' => '400.00', 'status' => OrderStatus::Refunded])->save();
        $this->assertSame(390, $this->points->reversedFor($order), 'the rest when fully refunded, never twice');
    }

    public function test_a_cancelled_order_returns_the_points_used(): void
    {
        $this->give(300);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 300);
        $this->assertSame(0, $this->points->balance($this->customer));

        Order::sole()->transitionTo(OrderStatus::Cancelled);

        $this->assertSame(300, $this->points->balance($this->customer));
        $this->assertSame(300, (int) PointTransaction::where('type', Type::ReturnRedeem)->sum('points'));

        // Returned into the original lot: it keeps its original expiry.
        $this->assertSame(300, PointTransaction::where('type', Type::Adjust)->sole()->remaining);
    }

    public function test_a_failed_online_payment_returns_the_points_used(): void
    {
        Http::fake(fn () => Http::response(['id' => 'pr-1', 'url' => 'https://checkout.sandbox.hit-pay.com/pay/1', 'status' => 'pending'], 201));
        $this->give(200);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 200, 'online');

        Order::sole()->markPaymentFailed();

        $this->assertSame(200, $this->points->balance($this->customer));
    }

    // --- Expiry -------------------------------------------------------------------------------------------

    public function test_points_are_used_and_expire_oldest_first(): void
    {
        $this->give(100);                 // lot A, expires 8 Nov 2027
        $this->travel(1)->months();
        $this->give(200);                 // lot B, expires 8 Dec 2027
        $this->travel(1)->months();
        $order = $this->codOrder(1000);
        $this->points->redeem($this->customer, $order, 200); // FIFO: all 100 of A, then 100 of B

        [$a, $b] = PointTransaction::where('type', Type::Adjust)->orderBy('id')->get()->all();
        $this->assertSame([0, 100], [$a->remaining, $b->remaining]);

        $this->travelTo(Carbon::parse('2027-11-09 00:00:00', 'UTC')); // A has expired, but it was already used
        $this->assertSame(0, $this->points->expireDue());
        $this->travelTo(Carbon::parse('2027-12-09 00:00:00', 'UTC')); // B expires: its last 100
        $this->assertSame(100, $this->points->expireDue());
        $this->assertSame(0, $this->points->balance($this->customer));
        $this->assertSame(-100, PointTransaction::where('type', Type::Expire)->sole()->points);
    }

    public function test_expire_command_and_balance_ignore_expired_points(): void
    {
        $this->give(150);
        $this->travel(13)->months();

        $this->assertSame(0, $this->points->balance($this->customer), 'expired points are not usable even before the job runs');
        $this->artisan('loyalty:expire')->expectsOutputToContain('Expired 150 points')->assertSuccessful();
        $this->assertSame(0, (int) PointTransaction::where('user_id', $this->customer->id)->orderByDesc('id')->value('balance_after'));
    }

    // --- Admin ---------------------------------------------------------------------------------------------

    public function test_admin_adjustment_needs_a_reason_and_is_logged(): void
    {
        $url = route('admin.customers.points.adjust', $this->customer);

        $this->actingAs($this->admin)->post($url, ['points' => 100, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post($url, ['points' => 0, 'reason' => 'Goodwill'])->assertSessionHasErrors('points');
        $this->assertSame(0, PointTransaction::count());

        $this->actingAs($this->admin)->post($url, ['points' => 120, 'reason' => 'Late delivery goodwill'])->assertSessionHas('success');
        $row = PointTransaction::sole();
        $this->assertSame([$this->admin->id, 120], [$row->admin_id, $row->points]);
        $this->assertStringContainsString('Late delivery goodwill', $row->description);

        $this->actingAs($this->admin)->post($url, ['points' => -500, 'reason' => 'Mistake'])->assertSessionHasErrors('points');
        $this->actingAs($this->admin)->get(route('admin.customers.points', $this->customer))
            ->assertOk()->assertSee(['120', 'Late delivery goodwill', 'admin #'.$this->admin->id]);
    }

    public function test_customers_cannot_see_other_customers_points(): void
    {
        $other = User::factory()->create();
        $this->give(700, $other);

        $this->actingAs($this->customer)->get(route('admin.customers.points', $other))->assertForbidden();
        $this->actingAs($this->customer)->post(route('admin.customers.points.adjust', $other), ['points' => 1, 'reason' => 'x'])->assertForbidden();
        $page = $this->actingAs($this->customer)->get(route('account.points'))->assertOk();
        $this->assertSame(0, $page->viewData('balance'));
        $this->assertCount(0, $page->viewData('history'));
    }

    public function test_admin_order_page_and_reports_show_points(): void
    {
        $this->give(300);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 200);
        $order = Order::sole();

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertSee(['Loyalty points', 'Used at checkout', '-200', 'Points discount (200 points)', '100 points']);
        $this->actingAs($this->admin)->get(route('admin.reports.index'))
            ->assertSee(['Points outstanding (now)', '100 pts · RM 5.00']);
    }

    // --- Customer pages ------------------------------------------------------------------------------------

    public function test_my_points_page(): void
    {
        $this->give(250);
        $this->travel(11)->months();
        $this->travel(15)->days(); // the 250 now expire within 30 days

        $this->actingAs($this->customer)->get(route('account.points'))
            ->assertOk()
            ->assertSee(['My points', '250', 'Worth RM 10.00', 'Expiring in the next 30 days', 'Adjusted by ShoeThrift']);
    }

    public function test_product_and_checkout_pages_show_points(): void
    {
        $pair = Product::factory()->create(['price' => 189.90, 'stock' => 1]);
        $this->get(route('shop.show', $pair->slug))->assertSee('Earn 189 points');

        $this->give(300);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $pair->id]);
        $this->get(route('checkout.create'))
            ->assertSee(['You have 300 points', 'Use points', '100 points: −RM 5.00', '200 points: −RM 10.00', '300 points: −RM 15.00'])
            ->assertSee("You'll earn <strong>189 points</strong>", false);
    }

    // --- Backfill --------------------------------------------------------------------------------------------

    public function test_backfill_dates_points_by_completion_and_expires_old_ones(): void
    {
        $old = $this->codOrder(200, ['status' => OrderStatus::Completed, 'paid_at' => now()->subMonths(14), 'delivered_at' => now()->subMonths(14)]);
        $recent = $this->codOrder(300, ['status' => OrderStatus::Completed, 'paid_at' => now()->subMonths(2), 'delivered_at' => now()->subMonths(2)]);
        $this->codOrder(500, ['status' => OrderStatus::Cancelled]);

        $this->artisan('loyalty:backfill')->assertSuccessful();
        $this->artisan('loyalty:backfill')->assertSuccessful(); // safe to run again

        $this->assertSame(2, PointTransaction::where('type', Type::Earn)->count());
        $this->assertSame(300, $this->points->balance($this->customer), 'the 14-month-old points have expired');
        $expire = PointTransaction::where('type', Type::Expire)->sole();
        $this->assertTrue($expire->created_at->equalTo($old->paid_at->copy()->addMonthsNoOverflow(12)), 'expiry dated at the real expiry date');
        $this->assertTrue(PointTransaction::where('order_id', $recent->id)->sole()->created_at->equalTo($recent->paid_at));
    }
}
