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
 * 100 points = RM1, from 500 points in steps of 100, at most 20% of the items, expiry after 12 months (FIFO).
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

    /** Checkout with "Use my points" ticked when `$points` > 0 (or forced with `$usePoints`). */
    private function checkout(Product $product, int|string|null $points, string $method = 'cod', ?bool $usePoints = null)
    {
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $product->id]);

        return $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah Rahman', 'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => number_format((float) $product->price + 10, 2, '.', ''), // before points
            'payment_method' => $method, 'points' => $points,
            ...(($usePoints ?? (int) $points > 0) ? ['use_points' => '1'] : []),
        ]);
    }

    /** The checkout page's points box limit (what "Use maximum" fills in). */
    private function checkoutMax(): int
    {
        preg_match('/data-points-box\s+data-min="\d+" data-max="(\d+)"/', $this->get(route('checkout.create'))->getContent(), $m);

        return (int) ($m[1] ?? -1);
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
        $this->give(800);
        $pair = Product::factory()->create(['price' => 200, 'stock' => 1]);

        $this->checkout($pair, 500)->assertRedirect();

        $order = Order::sole();
        // 500 points = RM5: RM200 + RM10 shipping - RM5.
        $this->assertSame([500, '5.00', '205.00', '200.00'], [$order->points_redeemed, $order->points_discount, $order->total, $order->subtotal]);
        $this->assertSame(300, $this->points->balance($this->customer));

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee(['Points discount (500 points)', '−RM 5.00']);
    }

    /**
     * @return array<string, array{int, int, float, string}>
     */
    public static function badRedemptions(): array
    {
        return [
            'below the minimum' => [400, 1000, 300, 'You can use points from 500 points.'],
            'not a multiple of 100' => [550, 1000, 300, 'Use points in steps of 100'],
            'above the 20% cap' => [6100, 10000, 300, 'at most 20% of the items (RM 60.00), so up to 6,000 points on this order.'],
            'above the balance' => [800, 600, 300, 'You only have 600 points.'],
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
        config(['loyalty.redeem_min_points' => 700]);
        $this->give(1000);

        $this->assertSame('You can use points from 700 points.', $this->points->redeemError(600, 1000, 1000));
        $this->assertNull($this->points->redeemError(700, 1000, 1000));
        $this->assertSame('100 points = RM1 off, up to 20% of the items, minimum 700 points.', $this->points->ruleText());
    }

    public function test_redeem_value_and_cap_come_from_config(): void
    {
        $this->assertSame('5.00', $this->points->discountFor(500));
        $this->assertSame('100 points = RM1 off, up to 20% of the items, minimum 500 points.', $this->points->ruleText());

        config(['loyalty.redeem_block_value' => 2.50, 'loyalty.redeem_max_share' => 0.1]);
        $this->assertSame('12.50', $this->points->discountFor(500));
        $this->assertSame('100 points = RM2.50 off, up to 10% of the items, minimum 500 points.', $this->points->ruleText());
        $this->assertSame(800, $this->points->maxRedeemable(5000, 200)); // 10% of RM200 = RM20 = 8 steps of RM2.50
    }

    public function test_exactly_20_percent_of_the_items_is_allowed(): void
    {
        $this->assertSame(5000, $this->points->maxRedeemable(5000, 250)); // 5000 points = RM50 = 20% of RM250
        $this->assertNull($this->points->redeemError(5000, 5000, 250));
        $this->assertStringContainsString('at most 20% of the items', $this->points->redeemError(5100, 9999, 250));

        $this->assertSame(3700, $this->points->maxRedeemable(9999, 189.90), 'cap RM37.98 rounds down to whole steps');
        $this->assertSame(0, $this->points->maxRedeemable(499, 1000), 'balance below the minimum');
        $this->assertSame(0, $this->points->maxRedeemable(10000, 20), 'cap (RM4 = 400 points) below the minimum');
        $this->assertStringContainsString("points can't be used on this order", $this->points->redeemError(500, 10000, 20));
    }

    public function test_concurrent_redemptions_cannot_overspend(): void
    {
        // Two checkouts racing: each redeem locks the user row and re-reads the balance, so the second loses.
        $this->give(1000);
        $first = $this->codOrder(400);
        $second = $this->codOrder(400);

        $this->points->redeem($this->customer, $first, 600);
        try {
            $this->points->redeem($this->customer, $second, 600);
            $this->fail('The second redemption should have been refused.');
        } catch (PointsException $e) {
            $this->assertStringContainsString('only have 400 points', $e->getMessage());
        }

        $this->assertSame(400, $this->points->balance($this->customer));
        $this->assertGreaterThanOrEqual(0, PointTransaction::min('balance_after'));
    }

    public function test_a_failed_checkout_rolls_the_points_back(): void
    {
        $this->give(800);
        $pair = Product::factory()->create(['price' => 200, 'stock' => 1]);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $pair->id]);
        $pair->update(['price' => 250]); // price changed: PlaceOrder throws after the cart was priced

        $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah', 'phone' => '012-345 6789', 'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam',
            'state' => 'Selangor', 'expected_total' => '210.00', 'payment_method' => 'cod', 'use_points' => '1', 'points' => 500,
        ]);

        $this->assertSame(0, Order::count());
        $this->assertSame(800, $this->points->balance($this->customer));
    }

    public function test_points_are_only_used_when_the_box_is_ticked(): void
    {
        $this->give(800);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);

        // Unticked: a number left in the (hidden) field is ignored.
        $this->checkout($pair, 500, usePoints: false)->assertRedirect();

        $this->assertSame([0, '0.00', '310.00'], [Order::sole()->points_redeemed, Order::sole()->points_discount, Order::sole()->total]);
        $this->assertSame(800, $this->points->balance($this->customer));
    }

    public function test_ticking_the_box_needs_a_number(): void
    {
        $this->give(800);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);

        $this->checkout($pair, '', usePoints: true)->assertSessionHasErrors(['points' => 'Enter how many points to use, or untick "Use my points".']);
        $this->assertSame(0, Order::count());
    }

    public function test_use_maximum_fills_the_most_this_order_allows(): void
    {
        // Limited by the 20% cap: RM300 of items -> RM60 -> 6,000 points, though the customer has 9,000.
        $this->give(9000);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $pair->id]);
        $this->assertSame(6000, $this->checkoutMax());

        $this->checkout($pair, 6000)->assertRedirect();
        $this->assertSame(['60.00', '250.00'], [Order::sole()->points_discount, Order::sole()->total]);
        $this->assertSame(3000, $this->points->balance($this->customer));
    }

    public function test_use_maximum_is_limited_by_the_balance(): void
    {
        // Limited by the balance, in whole steps: 1,250 points -> 1,200 (the cap would allow 6,000).
        $this->give(1250);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $pair->id]);
        $this->assertSame(1200, $this->checkoutMax());

        $this->checkout($pair, 1200)->assertRedirect();
        $this->assertSame(['12.00', '298.00'], [Order::sole()->points_discount, Order::sole()->total]);
        $this->assertSame(50, $this->points->balance($this->customer));
    }

    public function test_orders_placed_earlier_keep_their_stored_discount(): void
    {
        $this->give(800);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 500);
        $order = Order::sole();

        // An order placed under the old rules (100 points = RM5), stored as it was.
        $old = $this->codOrder(300, ['points_redeemed' => 200, 'points_discount' => '10.00', 'total' => '300.00']);

        config(['loyalty.redeem_block_value' => 3.00]); // changing the rules again later
        $this->assertSame(['5.00', '305.00'], [$order->fresh()->points_discount, $order->fresh()->total]);
        $this->actingAs($this->customer)->get(route('account.orders.show', $old->order_number))
            ->assertSee(['Points discount (200 points)', '−RM 10.00']);
    }

    // --- Payment amounts --------------------------------------------------------------------------------

    public function test_hitpay_is_asked_for_the_discounted_total(): void
    {
        Http::fake(fn (HttpRequest $r) => Http::response(['id' => 'pr-1', 'url' => 'https://checkout.sandbox.hit-pay.com/pay/1', 'status' => 'pending'], 201));
        $this->give(600);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);

        $this->checkout($pair, 600, 'online')->assertRedirect('https://checkout.sandbox.hit-pay.com/pay/1');

        $this->assertSame('304.00', Order::sole()->total); // 300 + 10 shipping - RM6
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['amount'] === '304.00');
    }

    public function test_cod_collects_the_discounted_total(): void
    {
        $this->give(500);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 500);

        $order = $this->complete(Order::sole());

        $this->assertSame('305.00', $order->total); // 300 + 10 - RM5: what the courier collects
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
        $this->give(500);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 500);
        $this->assertSame(0, $this->points->balance($this->customer));

        Order::sole()->transitionTo(OrderStatus::Cancelled);

        $this->assertSame(500, $this->points->balance($this->customer));
        $this->assertSame(500, (int) PointTransaction::where('type', Type::ReturnRedeem)->sum('points'));

        // Returned into the original lot: it keeps its original expiry.
        $this->assertSame(500, PointTransaction::where('type', Type::Adjust)->sole()->remaining);
    }

    public function test_a_failed_online_payment_returns_the_points_used(): void
    {
        Http::fake(fn () => Http::response(['id' => 'pr-1', 'url' => 'https://checkout.sandbox.hit-pay.com/pay/1', 'status' => 'pending'], 201));
        $this->give(500);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 500, 'online');

        Order::sole()->markPaymentFailed();

        $this->assertSame(500, $this->points->balance($this->customer));
    }

    // --- Expiry -------------------------------------------------------------------------------------------

    public function test_points_are_used_and_expire_oldest_first(): void
    {
        $this->give(300);                 // lot A, expires 8 Nov 2027
        $this->travel(1)->months();
        $this->give(400);                 // lot B, expires 8 Dec 2027
        $this->travel(1)->months();
        $order = $this->codOrder(1000);
        $this->points->redeem($this->customer, $order, 500); // FIFO: all 300 of A, then 200 of B

        [$a, $b] = PointTransaction::where('type', Type::Adjust)->orderBy('id')->get()->all();
        $this->assertSame([0, 200], [$a->remaining, $b->remaining]);

        $this->travelTo(Carbon::parse('2027-11-09 00:00:00', 'UTC')); // A has expired, but it was already used
        $this->assertSame(0, $this->points->expireDue());
        $this->travelTo(Carbon::parse('2027-12-09 00:00:00', 'UTC')); // B expires: its last 200
        $this->assertSame(200, $this->points->expireDue());
        $this->assertSame(0, $this->points->balance($this->customer));
        $this->assertSame(-200, PointTransaction::where('type', Type::Expire)->sole()->points);
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
        $this->give(1250);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->checkout($pair, 500);
        $order = Order::sole();

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertSee(['Loyalty points', 'Used at checkout', '-500', 'Points discount (500 points)', '750 points']);
        // Outstanding is valued at the current rate (100 points = RM1), to the sen.
        $this->actingAs($this->admin)->get(route('admin.reports.index'))
            ->assertSee(['Points outstanding (now)', '750 pts · RM 7.50']);
    }

    // --- Customer pages ------------------------------------------------------------------------------------

    public function test_my_points_page(): void
    {
        $this->give(250);
        $this->travel(11)->months();
        $this->travel(15)->days(); // the 250 now expire within 30 days

        $this->actingAs($this->customer)->get(route('account.points'))
            ->assertOk()
            ->assertSee(['My points', '250', 'Worth RM 2.00', 'You can use points from 500 points.', 'Expiring in the next 30 days', 'Adjusted by ShoeThrift'])
            ->assertSee('100 points = RM1 off, up to 20% of the items, minimum 500 points.');
    }

    public function test_product_and_checkout_pages_show_points(): void
    {
        $pair = Product::factory()->create(['price' => 189.90, 'stock' => 1]);
        $this->get(route('shop.show', $pair->slug))->assertSee('Earn 189 points');

        $this->give(5000);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $pair->id]);
        $this->get(route('checkout.create'))
            ->assertSee(['You have 5,000 points', '(worth RM 50.00)', 'Use my points', 'Use maximum',
                'Up to 3,700 points (−RM 37.00) on this order.', '100 points = RM1 off, up to 20% of the items, minimum 500 points.'])
            ->assertSee('min="500" max="3700" step="100"', false)
            ->assertDontSee('<select id="points"', false)
            ->assertSee("You'll earn <strong>189 points</strong>", false);
    }

    public function test_checkout_hides_the_points_field_below_the_minimum(): void
    {
        $this->give(400);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $pair->id]);

        $this->get(route('checkout.create'))
            ->assertSee(['You have 400 points', 'minimum 500 points'])
            ->assertDontSee('Use my points');
    }

    public function test_a_rejected_redemption_keeps_the_box_ticked_with_the_number(): void
    {
        $this->give(1000);
        $pair = Product::factory()->create(['price' => 300, 'stock' => 1]);

        $this->checkout($pair, 550)->assertRedirect(route('checkout.create'));

        $page = $this->get(route('checkout.create'))
            ->assertSee('Use points in steps of 100')
            ->assertSee('value="550"', false);
        $this->assertMatchesRegularExpression('/data-use-points\s+checked/', $page->getContent());
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
