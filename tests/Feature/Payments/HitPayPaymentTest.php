<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ShoppingCart;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HitPayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.sandbox.hit-pay.com/v1/payment-requests';

    private const SALT = 'test-webhook-salt'; // from phpunit.xml

    private User $customer;

    private Product $dunk;

    /** @var array<string, array<string, mixed>> payment request id => what HitPay's GET returns */
    private array $remote = [];

    private bool $hitPayDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['phone' => '012-345 6789', 'address' => '12 Jalan Bunga, 40100 Shah Alam']);
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'price' => 300, 'stock' => 1]);

        // A fake HitPay API: POST creates a payment request; GET returns $this->remote[$id].
        Http::fake(function (HttpRequest $request) {
            if (! str_starts_with($request->url(), self::API)) {
                return null;
            }
            if ($this->hitPayDown) {
                return Http::response(['message' => 'Server error'], 500);
            }
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'pr-'.count($this->remote) + 1, 'url' => 'https://checkout.sandbox.hit-pay.com/pay/'.(count($this->remote) + 1), 'status' => 'pending'], 201);
            }

            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return isset($this->remote[$id]) ? Http::response($this->remote[$id]) : Http::response(['message' => 'Not found'], 404);
        });
    }

    // --- Helpers ----------------------------------------------------------

    private function checkout(string $method)
    {
        $this->actingAs($this->customer);
        $this->post(route('cart.items.store'), ['product_id' => $this->dunk->id]);

        return $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'payment_method' => $method,
            'expected_total' => '310.00',
        ]);
    }

    /**
     * An online order for the Dunk (already sold, stock 0), with an open HitPay request "pr-1".
     */
    private function onlineOrder(array $attributes = []): Order
    {
        $this->dunk->update(['stock' => 0, 'status' => ProductStatus::Sold]);
        $order = Order::factory()->for($this->customer)->online()->create([
            'order_number' => 'TS-20261022-ABCDEF',
            'total' => 310,
            'shipping_fee' => 10,
            'gateway_reference' => 'pr-1',
            'payment_url' => 'https://checkout.sandbox.hit-pay.com/pay/1',
            ...$attributes,
        ]);
        OrderItem::factory()->for($order)->for($this->dunk)->create(['price' => 300]);

        return $order;
    }

    /**
     * What HitPay's GET /v1/payment-requests/{id} returns for this order.
     */
    private function remoteFor(Order $order, string $id = 'pr-1', string $status = 'completed', string $amount = '310.00', string $currency = 'MYR', string $paymentStatus = 'succeeded'): void
    {
        $this->remote[$id] = [
            'id' => $id,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'reference_number' => $order->order_number,
            'payments' => $status === 'pending' ? [] : [['id' => 'pay-'.$id, 'status' => $paymentStatus, 'amount' => $amount, 'currency' => strtolower($currency)]],
        ];
    }

    /**
     * POST a webhook the way HitPay does: raw JSON body + Hitpay-* headers.
     */
    private function webhook(array $payload, ?string $signature = 'valid', string $object = 'payment_request'): TestResponse
    {
        $raw = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_HITPAY_EVENT_TYPE' => 'completed', 'HTTP_HITPAY_EVENT_OBJECT' => $object, 'HTTP_USER_AGENT' => 'HitPay v2.0'];

        if ($signature !== null) {
            $headers['HTTP_HITPAY_SIGNATURE'] = $signature === 'valid' ? hash_hmac('sha256', $raw, self::SALT) : $signature;
        }

        return $this->call('POST', '/webhooks/hitpay', [], [], [], $headers, $raw);
    }

    private function getRequestsTo(string $id): int
    {
        return count(Http::recorded(fn (HttpRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/'.$id)));
    }

    // --- Checkout ---------------------------------------------------------

    public function test_online_checkout_creates_a_pending_order_and_redirects_to_hitpay(): void
    {
        $this->checkout('online')->assertRedirect('https://checkout.sandbox.hit-pay.com/pay/1');

        $order = Order::sole();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(PaymentMethod::Online, $order->payment_method);
        $this->assertSame('pr-1', $order->gateway_reference);
        $this->assertSame('https://checkout.sandbox.hit-pay.com/pay/1', $order->payment_url);
        $this->assertNull($order->paid_at);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $order->payment_expires_at->timestamp, 5);
        $this->assertSame(0, $this->dunk->fresh()->stock, 'Stock is reserved while awaiting payment.');
        $this->assertSame(0, app(ShoppingCart::class)->count());

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->url() === self::API
            && $r->hasHeader('X-BUSINESS-API-KEY', 'test-api-key')
            && $r->hasHeader('X-Requested-With', 'XMLHttpRequest')
            && $r->isForm()
            && $r['amount'] === '310.00'
            && $r['currency'] === 'MYR'
            && $r['reference_number'] === $order->order_number
            && $r['email'] === $this->customer->email
            && $r['name'] === 'Aisyah Rahman'
            && $r['redirect_url'] === route('account.orders.show', $order->order_number)
            && $r['expires_after'] === '30 mins'
            && ! isset($r['payment_methods'])
            && ! isset($r['webhook'])); // deprecated per the docs; events are registered in the dashboard
    }

    public function test_if_hitpay_is_down_the_order_is_kept_and_the_customer_can_try_again(): void
    {
        $this->hitPayDown = true;

        $this->checkout('online')
            ->assertRedirect(route('account.orders.show', 'TS-'.now()->format('Ymd').'-'.substr(Order::sole()->order_number, -6)))
            ->assertSessionHas('error');

        $order = Order::sole();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->payment_url);

        $this->get(route('account.orders.show', $order->order_number))->assertSee('Try again');

        $this->hitPayDown = false;
        $this->post(route('account.orders.pay', $order->order_number))
            ->assertRedirect('https://checkout.sandbox.hit-pay.com/pay/1');
        $this->assertSame('pr-1', $order->fresh()->gateway_reference);
    }

    public function test_pay_now_reuses_the_existing_hitpay_link(): void
    {
        $order = $this->onlineOrder();

        $this->actingAs($this->customer)
            ->post(route('account.orders.pay', $order->order_number))
            ->assertRedirect('https://checkout.sandbox.hit-pay.com/pay/1');

        Http::assertNothingSent();
    }

    public function test_pay_is_refused_for_expired_paid_or_other_peoples_orders(): void
    {
        $expired = $this->onlineOrder(['payment_expires_at' => now()->subMinute()]);
        $this->actingAs($this->customer)->post(route('account.orders.pay', $expired->order_number))
            ->assertSessionHas('error', 'This order can no longer be paid online.');

        $theirs = Order::factory()->online()->create();
        $this->actingAs($this->customer)->post(route('account.orders.pay', $theirs->order_number))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_cash_on_delivery_never_calls_hitpay(): void
    {
        $this->checkout('cod')->assertRedirect(route('checkout.complete', Order::sole()->order_number));

        $order = Order::sole();
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->payment_expires_at);
        Http::assertNothingSent();

        $this->get(route('checkout.complete', $order->order_number))->assertSee('Cash on Delivery.');
    }

    public function test_payment_method_is_required(): void
    {
        $this->checkout('bitcoin')->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('orders', 0);
    }

    // --- Webhook ----------------------------------------------------------

    public function test_verified_webhook_marks_the_order_paid(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order);

        $this->webhook(['id' => 'pr-1', 'status' => 'completed', 'reference_number' => $order->order_number])
            ->assertOk()
            ->assertSee('OK (paid)');

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('pr-1', $order->gateway_reference);
        $this->assertSame('pay-pr-1', $order->gateway_payment_id);
        $this->assertSame(1, $this->getRequestsTo('pr-1'), 'The status is confirmed with HitPay server-to-server.');
    }

    public function test_invalid_signature_is_rejected_and_changes_nothing(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order);

        $this->webhook(['id' => 'pr-1'], signature: hash_hmac('sha256', '{"id":"pr-1"}', 'wrong-salt'))->assertStatus(401);
        $this->webhook(['id' => 'pr-1'], signature: null)->assertStatus(401);
        $this->webhook(['id' => 'pr-1'], signature: '')->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_tampered_body_fails_verification(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order);
        $signatureOfOriginal = hash_hmac('sha256', json_encode(['id' => 'pr-other']), self::SALT);

        $this->webhook(['id' => 'pr-1'], signature: $signatureOfOriginal)->assertStatus(401);

        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_duplicate_webhooks_are_harmless(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order);

        $this->webhook(['id' => 'pr-1'])->assertOk()->assertSee('OK (paid)');
        $paidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();
        $this->webhook(['id' => 'pr-1'])->assertOk()->assertSee('OK (already_paid)');
        $this->webhook(['id' => 'pr-1'])->assertOk()->assertSee('OK (already_paid)');

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertTrue($paidAt->equalTo($order->paid_at), 'paid_at is not overwritten.');
        $this->assertSame(0, $this->dunk->fresh()->stock, 'Stock is untouched by repeats.');
    }

    public function test_the_browser_redirect_never_marks_an_order_paid(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order, status: 'pending'); // HitPay's own record: not paid

        // The return URL claims success; only HitPay's API answer counts.
        $this->actingAs($this->customer)
            ->get(route('account.orders.show', ['orderNumber' => $order->order_number, 'reference' => 'pr-1', 'status' => 'completed']))
            ->assertOk()
            ->assertSee('Pending, waiting for your payment')
            ->assertSee('http-equiv="refresh"', false);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(1, $this->getRequestsTo('pr-1'), 'The page asked HitPay instead of trusting the query string.');
    }

    public function test_paid_order_page_shows_payment_received(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order);
        $this->webhook(['id' => 'pr-1']);

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee('Paid '.$order->fresh()->paid_at->format('j M Y'))
            ->assertDontSee('Confirming your payment')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_amount_or_currency_mismatch_is_not_accepted(): void
    {
        $order = $this->onlineOrder();

        $this->remoteFor($order, amount: '1.00');
        $this->webhook(['id' => 'pr-1'])->assertOk()->assertSee('Ignored');
        $this->assertNull($order->fresh()->paid_at);

        $this->remoteFor($order, currency: 'SGD');
        $this->webhook(['id' => 'pr-1'])->assertOk()->assertSee('Ignored');
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_incomplete_or_failed_payment_requests_are_not_accepted(): void
    {
        $order = $this->onlineOrder();

        $this->remoteFor($order, status: 'pending');
        $this->webhook(['id' => 'pr-1'])->assertOk();

        $this->remoteFor($order, status: 'completed', paymentStatus: 'failed');
        $this->webhook(['id' => 'pr-1'])->assertOk();

        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_the_webhook_body_alone_is_not_trusted(): void
    {
        // A correctly signed body claiming "completed" is still checked against HitPay's API.
        $order = $this->onlineOrder();
        $this->remoteFor($order, status: 'pending');

        $this->webhook(['id' => 'pr-1', 'status' => 'completed', 'amount' => '310.00', 'reference_number' => $order->order_number])->assertOk();

        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_unknown_orders_and_other_event_types_are_ignored(): void
    {
        $this->remote['pr-9'] = ['id' => 'pr-9', 'status' => 'completed', 'amount' => '10.00', 'currency' => 'MYR', 'reference_number' => 'NOT-OURS', 'payments' => []];
        $this->webhook(['id' => 'pr-9'])->assertOk()->assertSee('Ignored: unknown order');

        $this->webhook(['id' => 'ch-1'], object: 'charge')->assertOk()->assertSee('Ignored: not a payment request event');
        $this->assertSame(0, $this->getRequestsTo('ch-1'));
    }

    public function test_cash_on_delivery_orders_cannot_be_paid_by_webhook(): void
    {
        $order = $this->onlineOrder(['payment_method' => PaymentMethod::CashOnDelivery, 'payment_expires_at' => null]);
        $this->remoteFor($order);

        $this->webhook(['id' => 'pr-1'])->assertOk()->assertSee('Ignored: unknown order');
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_hitpay_api_down_during_confirmation_asks_for_a_retry(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayDown = true;

        $this->webhook(['id' => 'pr-1'])->assertStatus(503);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_payment_after_cancellation_is_flagged_for_refund(): void
    {
        $order = $this->onlineOrder();
        $order->transitionTo(OrderStatus::Cancelled);
        $this->remoteFor($order);

        $this->webhook(['id' => 'pr-1'])->assertOk()->assertSee('OK (late)');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status, 'A cancelled order is not reopened.');
        $this->assertNotNull($order->paid_at);
        $this->assertTrue($order->needsRefund());

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.orders.show', $order))
            ->assertSee('Payment received after the order was cancelled, refund needed.');
        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee("We'll refund it to you.", false);
    }

    // --- 30-minute expiry ---------------------------------------------------

    public function test_unpaid_online_orders_fail_after_the_window_and_stock_is_restored(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order, status: 'pending');

        $this->travel(31)->minutes();
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('Failed 1')->assertSuccessful();

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->dunk->refresh();
        $this->assertSame(1, $this->dunk->stock);
        $this->assertSame(ProductStatus::Available, $this->dunk->status);
    }

    public function test_orders_within_the_window_and_cod_orders_are_left_alone(): void
    {
        $online = $this->onlineOrder();
        $cod = Order::factory()->cashOnDelivery()->create(['created_at' => now()->subDays(3)]);

        $this->travel(29)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $online->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $cod->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_paid_order_whose_webhook_was_lost_is_marked_paid_not_cancelled(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order); // HitPay says completed, but no webhook ever arrived

        $this->travel(31)->minutes();
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('confirmed paid 1')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(0, $this->dunk->fresh()->stock);
    }

    public function test_expiry_skips_an_order_when_hitpay_cannot_be_reached(): void
    {
        $order = $this->onlineOrder();
        $this->hitPayDown = true;

        $this->travel(31)->minutes();
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('skipped 1')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status, 'Not cancelled blind; retried next run.');
    }

    public function test_an_order_whose_payment_never_started_fails_without_asking_hitpay(): void
    {
        $order = $this->onlineOrder(['gateway_reference' => null, 'payment_url' => null]);

        $this->travel(31)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_expiry_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'orders:expire-unpaid'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    // --- Admin --------------------------------------------------------------

    public function test_admins_cannot_mark_online_orders_paid_by_hand(): void
    {
        $order = $this->onlineOrder();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.orders.show', $order))
            ->assertDontSee('name="status" value="paid"', false)
            ->assertSee('name="status" value="cancelled"', false)
            ->assertSee('Cancel order')
            ->assertSee(['Online payment (HitPay)', 'pr-1', 'Not paid yet']);

        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'paid'])
            ->assertSessionHas('error');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_admin_order_page_shows_gateway_references_once_paid(): void
    {
        $order = $this->onlineOrder();
        $this->remoteFor($order);
        $this->webhook(['id' => 'pr-1']);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.orders.show', $order))
            ->assertSee(['Online payment (HitPay)', 'pr-1', 'pay-pr-1', $order->fresh()->paid_at->shopDateTime()])
            ->assertSee('name="status" value="shipped"', false)
            ->assertSee('Mark shipped');
    }

    public function test_cash_on_delivery_flow_for_admins(): void
    {
        $order = Order::factory()->cashOnDelivery()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'paid'])->assertSessionHas('error');

        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'shipped'])->assertSessionHas('success');
        $this->assertNull($order->fresh()->paid_at);

        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'delivered'])->assertSessionHas('success');
        $this->assertNull($order->fresh()->paid_at, 'Delivered is not paid: the cash is confirmed separately.');

        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertSessionHas('success', "Order {$order->order_number} is now Cash collected.");
        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->paid_at, 'Completing a COD order records the cash as collected.');
    }

    public function test_admin_can_filter_orders_by_payment_method(): void
    {
        $online = $this->onlineOrder();
        $cod = Order::factory()->cashOnDelivery()->create(['order_number' => 'TS-20261022-CODCOD']);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.orders.index', ['payment' => 'cod']))
            ->assertSee('TS-20261022-CODCOD')
            ->assertDontSee($online->order_number);
    }
}
