<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Http\Controllers\AccountOrderController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The payment status check on the order page / "Check again" / expiry, the status page states,
 * receipts, the local-only simulate tool, ngrok/proxy URLs and hitpay:webhook-url.
 */
class PaymentStatusTest extends TestCase
{
    use FakesHitPay, RefreshDatabase;

    private User $customer;

    private Product $dunk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHitPay();
        $this->customer = User::factory()->create();
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'brand' => 'Nike', 'size' => 8.5, 'price' => 300, 'stock' => 1]);
    }

    /**
     * An online order awaiting payment for the Dunk (stock already reserved), with HitPay request pr-1.
     */
    private function onlineOrder(array $attributes = []): Order
    {
        $this->dunk->update(['stock' => 0, 'status' => ProductStatus::Sold]);
        $order = Order::factory()->for($this->customer)->online()->create([
            'order_number' => 'TS-20261022-PAYME2',
            'total' => 310,
            'shipping_fee' => 10,
            'gateway_reference' => 'pr-1',
            'payment_url' => 'https://checkout.sandbox.hit-pay.com/pay/1',
            ...$attributes,
        ]);
        OrderItem::factory()->for($order)->for($this->dunk)->create(['price' => 300]);

        return $order;
    }

    private function viewOrder(Order $order, array $query = [])
    {
        return $this->actingAs($this->customer)
            ->get(route('account.orders.show', ['orderNumber' => $order->order_number, ...$query]));
    }

    // --- 1. Status check when the customer returns / refreshes ---------------

    public function test_returning_from_hitpay_with_a_completed_payment_marks_the_order_paid(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'completed');

        $this->viewOrder($order, ['reference' => 'pr-1', 'status' => 'completed'])
            ->assertOk()
            ->assertSee('Payment received on')
            ->assertSee('View receipt')
            ->assertDontSee('http-equiv="refresh"', false);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('pay-pr-1', $order->gateway_payment_id);
        $this->assertSame(0, $this->dunk->fresh()->stock);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unpayableStatuses(): array
    {
        return ['failed' => ['failed'], 'expired' => ['expired'], 'canceled' => ['canceled'], 'inactive' => ['inactive']];
    }

    #[DataProvider('unpayableStatuses')]
    public function test_a_finished_unpaid_payment_request_marks_the_order_failed_and_restores_stock(string $hitPayStatus): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, $hitPayStatus);

        $this->viewOrder($order)
            ->assertOk()
            ->assertSee('Payment failed')
            ->assertSee('Shop again')
            ->assertDontSee('http-equiv="refresh"', false);

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->dunk->refresh();
        $this->assertSame(1, $this->dunk->stock);
        $this->assertSame(ProductStatus::Available, $this->dunk->status);
    }

    public function test_a_declined_card_on_a_still_open_request_is_not_a_failure(): void
    {
        // HitPay keeps the request pending so the customer can retry on its page.
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');

        $this->viewOrder($order)->assertSee('Pending, waiting for your payment');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, $this->dunk->fresh()->stock);
    }

    public function test_hitpay_unreachable_keeps_the_order_pending_and_says_so(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayDown = true;

        $this->viewOrder($order)->assertOk()->assertSee("We couldn't reach HitPay to check just now.", false);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_status_checks_are_throttled_to_one_per_ten_seconds(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');

        $this->viewOrder($order);
        $this->viewOrder($order, ['check' => 1]);
        $this->assertSame(1, $this->hitPayStatusChecks());

        $this->travel(11)->seconds();
        $this->viewOrder($order, ['check' => 2]);
        $this->assertSame(2, $this->hitPayStatusChecks());
    }

    public function test_paid_and_cod_orders_are_never_checked_with_hitpay(): void
    {
        $paid = $this->onlineOrder(['paid_at' => now(), 'status' => OrderStatus::Paid]);
        $cod = Order::factory()->for($this->customer)->cashOnDelivery()->create();

        $this->viewOrder($paid)->assertOk();
        $this->viewOrder($cod)->assertOk()->assertSee('Pending, Cash on Delivery');

        Http::assertNothingSent();
    }

    // --- Idempotency with the webhook ---------------------------------------

    public function test_page_check_then_webhook_applies_once(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'completed');

        $this->viewOrder($order);
        $paidAt = $order->fresh()->paid_at;

        $this->travel(1)->minutes();
        $this->signedWebhook('pr-1')->assertOk()->assertSee('OK (already_paid)');

        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at));
        $this->assertSame(0, $this->dunk->fresh()->stock);
    }

    public function test_webhook_then_page_check_applies_once(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'completed');

        $this->signedWebhook('pr-1')->assertSee('OK (paid)');
        $checksAfterWebhook = $this->hitPayStatusChecks();

        $this->viewOrder($order)->assertSee('Payment received on');

        $this->assertSame($checksAfterWebhook, $this->hitPayStatusChecks(), 'An already-paid order is not re-checked.');
    }

    public function test_failing_an_already_paid_order_does_nothing(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'completed');
        $this->signedWebhook('pr-1');

        $this->assertFalse($order->fresh()->markPaymentFailed());
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(0, $this->dunk->fresh()->stock, 'Stock is not restored for a paid order.');
    }

    public function test_a_payment_completing_after_the_order_failed_is_flagged_for_refund(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'expired');
        $this->viewOrder($order); // → failed, stock restored

        $this->hitPayHas($order, 'completed');
        $this->signedWebhook('pr-1')->assertSee('OK (late)');

        $order->refresh();
        $this->assertSame(OrderStatus::Failed, $order->status, 'Not reopened: the pair may have sold again.');
        $this->assertTrue($order->needsRefund());
        $this->viewOrder($order)->assertSee('Refund on its way');
    }

    public function test_marking_failed_twice_restores_stock_once(): void
    {
        $order = $this->onlineOrder();

        $this->assertTrue($order->markPaymentFailed());
        $this->assertFalse($order->fresh()->markPaymentFailed());

        $this->assertSame(1, $this->dunk->fresh()->stock);
    }

    // --- 3. Auto-refresh, timeout, Pay now / Check again ---------------------

    public function test_pending_page_auto_refreshes_with_an_incrementing_counter(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');

        $next = e(route('account.orders.show', ['orderNumber' => $order->order_number, 'check' => 4]));

        $this->viewOrder($order, ['check' => 3])
            ->assertSee('<meta http-equiv="refresh" content="'.AccountOrderController::REFRESH_SECONDS.';url='.$next.'">', false)
            ->assertSee('Pay now')
            ->assertDontSee('Check again');
    }

    public function test_auto_refresh_stops_after_the_limit_and_offers_pay_now_and_check_again(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');

        $this->viewOrder($order, ['check' => AccountOrderController::MAX_AUTO_CHECKS])
            ->assertSee('Pending, still waiting for confirmation')
            ->assertDontSee('http-equiv="refresh"', false)
            ->assertSee('Pay now')
            ->assertSee('Check again');
    }

    public function test_check_again_asks_hitpay_immediately_even_within_the_throttle_window(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');
        $this->viewOrder($order); // uses up the throttle window

        $this->hitPayHas($order, 'completed');
        $this->actingAs($this->customer)
            ->post(route('account.orders.check', $order->order_number))
            ->assertRedirect(route('account.orders.show', ['orderNumber' => $order->order_number, 'check' => AccountOrderController::MAX_AUTO_CHECKS]))
            ->assertSessionHas('success', 'Payment received. Thank you!');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(2, $this->hitPayStatusChecks());
    }

    public function test_check_again_reports_still_pending_and_unreachable(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');

        $this->actingAs($this->customer)->post(route('account.orders.check', $order->order_number))
            ->assertSessionHas('success', "HitPay hasn't received your payment yet. If you've just paid, give it a moment.");

        $this->hitPayDown = true;
        $this->actingAs($this->customer)->post(route('account.orders.check', $order->order_number))
            ->assertSessionHas('error', "We couldn't reach the payment provider. Please try again in a moment.");
    }

    public function test_cancelled_orders_are_shown_as_cancelled(): void
    {
        $order = $this->onlineOrder();
        $order->transitionTo(OrderStatus::Cancelled);

        $this->viewOrder($order)
            ->assertSee('This order was cancelled and its pairs were returned to the shop.')
            ->assertDontSee('Pay now')
            ->assertDontSee('http-equiv="refresh"', false);
        Http::assertNothingSent();
    }

    public function test_customers_cannot_check_other_peoples_orders(): void
    {
        $theirs = Order::factory()->online()->create(['gateway_reference' => 'pr-1']);

        $this->actingAs($this->customer)->get(route('account.orders.show', $theirs->order_number))->assertNotFound();
        $this->actingAs($this->customer)->post(route('account.orders.check', $theirs->order_number))->assertNotFound();
        Http::assertNothingSent();
    }

    // --- Expiry uses the same check ---------------------------------------------

    public function test_expiry_uses_hitpays_expired_status_to_fail_the_order(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'expired');

        $this->travel(31)->minutes();
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('Failed 1')->assertSuccessful();

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertSame(1, $this->dunk->fresh()->stock);
    }

    // --- 4. Receipt -------------------------------------------------------------

    public function test_owner_can_view_a_receipt_for_a_paid_order(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'completed');
        $this->signedWebhook('pr-1');
        $order->refresh();

        $this->actingAs($this->customer)->get(route('account.orders.receipt', $order->order_number))
            ->assertOk()
            ->assertSee(['Receipt', 'TS-20261022-PAYME2', $order->created_at->shopDate(), $order->paid_at->shopDate()])
            ->assertSee(['Nike Dunk Low', 'Nike · UK 8.5', 'RM 300.00', 'RM 10.00', 'RM 310.00'])
            ->assertSee(['Online payment (HitPay)', 'pr-1', 'pay-pr-1'])
            ->assertSee('Print / Save as PDF')
            ->assertSee('window.print()', false);
    }

    public function test_receipt_header_and_footer_are_hidden_when_printing(): void
    {
        $order = $this->onlineOrder(['status' => OrderStatus::Paid, 'paid_at' => now()]);

        $this->actingAs($this->customer)->get(route('account.orders.receipt', $order->order_number))
            ->assertSee('backdrop-blur print:hidden', false)   // site header
            ->assertSee('bg-white print:hidden', false);        // footer
    }

    public function test_no_receipt_for_unpaid_orders_or_other_customers(): void
    {
        $unpaid = $this->onlineOrder();
        $this->actingAs($this->customer)->get(route('account.orders.receipt', $unpaid->order_number))
            ->assertRedirect(route('account.orders.show', $unpaid->order_number))
            ->assertSessionHas('error', 'A receipt is available once the order is paid.');

        $theirs = Order::factory()->online()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
        $this->actingAs($this->customer)->get(route('account.orders.receipt', $theirs->order_number))->assertNotFound();

        $this->post('/logout');
        $this->get(route('account.orders.receipt', $unpaid->order_number))->assertRedirect(route('login'));
    }

    public function test_completed_cash_on_delivery_orders_get_a_receipt(): void
    {
        $order = Order::factory()->for($this->customer)->cashOnDelivery()->status(OrderStatus::Completed)->create(['paid_at' => now()]);

        $this->actingAs($this->customer)->get(route('account.orders.receipt', $order->order_number))
            ->assertOk()
            ->assertSee('Cash on Delivery');
    }

    public function test_my_orders_links_receipts_only_for_paid_orders(): void
    {
        $paid = $this->onlineOrder(['status' => OrderStatus::Paid, 'paid_at' => now()]);
        $pending = Order::factory()->for($this->customer)->cashOnDelivery()->create();

        $this->actingAs($this->customer)->get(route('account.orders.index'))
            ->assertSee(route('account.orders.receipt', $paid->order_number), false)
            ->assertDontSee(route('account.orders.receipt', $pending->order_number), false);
    }

    /**
     * Pretend APP_ENV=local. Laravel only skips CSRF checks in the "testing" environment, so
     * switch that one middleware off; the simulate route itself is what is under test here.
     */
    private function runAsLocal(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // --- 5. Local-only "Simulate payment success" ------------------------------

    public function test_simulate_button_is_hidden_and_disabled_outside_local(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');
        $this->assertFalse(app()->environment('local'));

        $this->viewOrder($order)->assertDontSee('Simulate payment success');
        $this->actingAs($this->customer)->post(route('account.orders.simulate', $order->order_number))->assertNotFound();

        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_simulate_marks_the_order_paid_in_local_and_is_clearly_labelled(): void
    {
        $this->runAsLocal();
        $order = $this->onlineOrder();
        $this->hitPayHas($order, 'pending');

        $this->viewOrder($order)
            ->assertSee('Test tool (local only). Not a real payment.')
            ->assertSee('Simulate payment success');

        $this->actingAs($this->customer)->post(route('account.orders.simulate', $order->order_number))
            ->assertRedirect(route('account.orders.show', $order->order_number))
            ->assertSessionHas('success', 'Test payment simulated (local only). No real money moved.');

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertTrue($order->isSimulatedPayment());
        $this->assertStringStartsWith('SIMULATED-', $order->gateway_payment_id);

        $this->actingAs($this->customer)->get(route('account.orders.receipt', $order->order_number))->assertSee('(Simulated test payment)');
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.orders.show', $order))->assertSee('Simulated test payment (local only), not real money');

        // A second click changes nothing.
        $paidAt = $order->paid_at;
        $this->actingAs($this->customer)->post(route('account.orders.simulate', $order->order_number))->assertSessionHas('error');
        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at));
    }

    public function test_simulate_only_works_on_your_own_order(): void
    {
        $this->runAsLocal();
        $theirs = Order::factory()->online()->create();

        $this->actingAs($this->customer)->post(route('account.orders.simulate', $theirs->order_number))->assertNotFound();
        $this->assertNull($theirs->fresh()->paid_at);
    }

    // --- 2. ngrok / trusted proxy / URLs ---------------------------------------

    private function viaNgrok(string $ip = '127.0.0.1'): static
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => $ip,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'abc123.ngrok-free.dev',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]);
    }

    public function test_links_use_the_ngrok_https_address_when_proxied_from_localhost(): void
    {
        $this->viaNgrok()->get('/')
            ->assertOk()
            ->assertSee('action="https://abc123.ngrok-free.dev/shop"', false)
            ->assertDontSee('http://localhost', false);
    }

    public function test_forwarded_headers_from_other_ips_are_ignored(): void
    {
        $this->viaNgrok('203.0.113.9')->get('/')
            ->assertSee('action="http://localhost/shop"', false)
            ->assertDontSee('ngrok-free.dev', false);
    }

    public function test_hitpay_return_url_follows_the_address_the_customer_is_using(): void
    {
        $this->actingAs($this->customer);
        $this->post(route('cart.items.store'), ['product_id' => $this->dunk->id]);

        $this->viaNgrok()->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah', 'phone' => '012-345 6789', 'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'payment_method' => 'online', 'expected_total' => '310.00',
        ])->assertRedirect('https://checkout.sandbox.hit-pay.com/pay/1');

        $order = Order::sole();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['redirect_url'] === 'https://abc123.ngrok-free.dev/account/orders/'.$order->order_number);
    }

    public function test_webhook_url_command_prints_the_url_from_hitpay_public_url(): void
    {
        config(['services.hitpay.public_url' => 'https://abc123.ngrok-free.dev/']);

        $this->artisan('hitpay:webhook-url')
            ->expectsOutputToContain('https://abc123.ngrok-free.dev/webhooks/hitpay')
            ->expectsOutputToContain('payment_request.completed')
            ->assertSuccessful();
    }

    public function test_webhook_url_command_warns_when_missing_or_not_https(): void
    {
        config(['services.hitpay.public_url' => null]);
        $this->artisan('hitpay:webhook-url')->expectsOutputToContain('HITPAY_PUBLIC_URL is not set')->assertFailed();

        config(['services.hitpay.public_url' => 'http://abc123.ngrok-free.dev']);
        $this->artisan('hitpay:webhook-url')->expectsOutputToContain('should start with https://')->assertSuccessful();
    }
}
