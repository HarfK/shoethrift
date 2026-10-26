<?php

namespace Tests\Feature\Checkout;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ShoppingCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cash on Delivery: pending → shipped → delivered → cash collected, or failed delivery
 * (cancelled + restocked + counted), and blocking COD for repeat failures / large orders.
 */
class CashOnDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Product $dunk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create(['name' => 'Aisyah Rahman', 'phone' => '011-111 1111']);
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'size' => 8.5, 'price' => 300, 'stock' => 1]);
    }

    /**
     * A COD order holding the (now sold) Dunk, as checkout leaves it.
     */
    private function codOrder(OrderStatus $status = OrderStatus::Pending, ?User $customer = null): Order
    {
        $this->dunk->update(['stock' => 0, 'status' => ProductStatus::Sold]);

        $order = Order::factory()->for($customer ?? $this->customer)->cashOnDelivery()->status($status)->create([
            'total' => 310,
            'shipping_fee' => 10,
            'shipping_phone' => '012-222 2222',
        ]);
        OrderItem::factory()->for($order)->for($this->dunk)->create(['price' => 300]);

        return $order;
    }

    private function changeStatus(Order $order, string $status)
    {
        return $this->actingAs($this->admin)->patch(route('admin.orders.status', $order), ['status' => $status]);
    }

    private function failedDeliveries(User $customer, int $count): void
    {
        Order::factory()->count($count)->for($customer)->cashOnDelivery()->status(OrderStatus::Cancelled)
            ->create(['delivery_failed_at' => now()]);
    }

    private function checkoutWith(string $method)
    {
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $this->dunk->id]);

        return $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => '310.00',
            'payment_method' => $method,
        ]);
    }

    // --- The flow ---------------------------------------------------------

    public function test_full_flow_shipped_delivered_cash_collected(): void
    {
        $order = $this->codOrder();

        $this->changeStatus($order, 'shipped')->assertSessionHas('success');
        $this->changeStatus($order, 'delivered')->assertSessionHas('success', "Order {$order->order_number} is now Delivered.");
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee('Delivered, Cash on Delivery')
            ->assertSeeInOrder(['Placed', 'Shipped', 'Delivered', 'Cash collected']);

        $this->changeStatus($order, 'completed')->assertSessionHas('success', "Order {$order->order_number} is now Cash collected.");
        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertTrue($order->hasReceipt());
        $this->assertSame(ProductStatus::Sold, $this->dunk->fresh()->status);

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee('Cash collected')
            ->assertSee('View receipt');
    }

    public function test_admin_sees_one_button_per_allowed_step(): void
    {
        $expected = [
            'pending' => ['Mark shipped', 'Cancel order'],
            'shipped' => ['Mark delivered', 'Failed delivery'],
            'delivered' => ['Cash collected', 'Failed delivery'],
        ];

        foreach ($expected as $status => $buttons) {
            $order = $this->codOrder(OrderStatus::from($status));

            $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
                ->assertSeeInOrder($buttons)
                ->assertDontSee('Update status');
        }
    }

    /**
     * @return array<string, array{OrderStatus, string}>
     */
    public static function disallowedSteps(): array
    {
        return [
            'pending → delivered' => [OrderStatus::Pending, 'delivered'],
            'pending → cash collected' => [OrderStatus::Pending, 'completed'],
            'shipped → cash collected (skipping delivered)' => [OrderStatus::Shipped, 'completed'],
            'delivered → shipped' => [OrderStatus::Delivered, 'shipped'],
            'cash collected → failed delivery' => [OrderStatus::Completed, 'cancelled'],
            'COD never becomes "paid"' => [OrderStatus::Shipped, 'paid'],
        ];
    }

    #[DataProvider('disallowedSteps')]
    public function test_disallowed_steps_are_rejected(OrderStatus $from, string $to): void
    {
        $order = $this->codOrder($from);

        $this->changeStatus($order, $to)->assertSessionHas('error');

        $this->assertSame($from, $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_failed_at);
    }

    public function test_online_orders_have_no_delivered_step(): void
    {
        $order = Order::factory()->online()->status(OrderStatus::Shipped)->create();

        $this->changeStatus($order, 'delivered')->assertSessionHas('error');
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    // --- Failed delivery --------------------------------------------------

    /**
     * @return array<string, array{OrderStatus}>
     */
    public static function failableStatuses(): array
    {
        return ['from shipped' => [OrderStatus::Shipped], 'from delivered' => [OrderStatus::Delivered]];
    }

    #[DataProvider('failableStatuses')]
    public function test_failed_delivery_cancels_restocks_and_counts(OrderStatus $from): void
    {
        $order = $this->codOrder($from);

        $this->changeStatus($order, 'cancelled')
            ->assertSessionHas('success', "Order {$order->order_number} was marked as a failed delivery: it's cancelled and its items were returned to stock.");

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->delivery_failed_at);
        $this->assertNull($order->paid_at);
        $this->assertSame(1, $this->dunk->fresh()->stock);
        $this->assertSame(ProductStatus::Available, $this->dunk->fresh()->status);
        $this->assertSame(1, $this->customer->failedCodCount());

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee('Failed delivery')
            ->assertSee('Delivery was unsuccessful, so this order was cancelled');
    }

    public function test_cancelling_a_pending_cod_order_is_not_a_failed_delivery(): void
    {
        $order = $this->codOrder();

        $this->changeStatus($order, 'cancelled')
            ->assertSessionHas('success', "Order {$order->order_number} was cancelled and its items were returned to stock.");

        $this->assertNull($order->fresh()->delivery_failed_at);
        $this->assertSame(0, $this->customer->failedCodCount());
        $this->assertSame(1, $this->dunk->fresh()->stock);
    }

    public function test_a_failed_delivery_cannot_be_restocked_twice(): void
    {
        $order = $this->codOrder(OrderStatus::Shipped);

        $this->changeStatus($order, 'cancelled')->assertSessionHas('success');
        $this->changeStatus($order, 'cancelled')->assertSessionHas('error');

        $this->assertSame(1, $this->dunk->fresh()->stock);
    }

    // --- Admin visibility -------------------------------------------------

    public function test_admin_sees_the_failed_cod_count(): void
    {
        $order = $this->codOrder();

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertSee('Failed COD orders:')
            ->assertDontSee('COD blocked');

        $this->failedDeliveries($this->customer, 2);

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertSee('COD blocked');
        $this->actingAs($this->admin)->get(route('admin.orders.index'))
            ->assertSee('2 failed COD · blocked')
            ->assertSee('Failed delivery');
    }

    // --- Blocking COD at checkout -----------------------------------------

    public function test_one_failed_delivery_still_allows_cod(): void
    {
        $this->failedDeliveries($this->customer, 1);

        $this->checkoutWith('cod')->assertRedirect();

        $this->assertSame(1, Order::where('delivery_failed_at', null)->count());
    }

    public function test_cod_is_blocked_after_two_failed_deliveries(): void
    {
        $this->failedDeliveries($this->customer, 2);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $this->dunk->id]);

        $this->get(route('checkout.create'))
            ->assertSee("Cash on Delivery isn't available on your account because of 2 failed deliveries. Please pay online.")
            ->assertSeeInOrder(['value="cod"', 'disabled', 'aria-describedby="cod-unavailable"'], false);

        $this->checkoutWith('cod')
            ->assertRedirect(route('checkout.create'))
            ->assertSessionHasErrors(['payment_method' => "Cash on Delivery isn't available on your account because of 2 failed deliveries. Please pay online."]);

        $this->assertSame(2, Order::count(), 'No new order was placed.');
        $this->assertSame(1, $this->dunk->fresh()->stock, 'Stock untouched.');
        $this->assertSame(1, app(ShoppingCart::class)->count(), 'The cart is kept so they can pay online.');
    }

    public function test_the_failed_delivery_limit_comes_from_config(): void
    {
        config(['shop.cod.max_failed_deliveries' => 3]);
        $this->failedDeliveries($this->customer, 2);

        $this->checkoutWith('cod')->assertSessionHasNoErrors();
        $this->assertFalse($this->customer->isCodBlocked());
    }

    public function test_optional_maximum_cod_total(): void
    {
        config(['shop.cod.max_total' => 200]);

        $this->checkoutWith('cod')
            ->assertSessionHasErrors(['payment_method' => 'Cash on Delivery is only available for orders up to RM 200.00. Please pay online.']);
        $this->assertSame(0, Order::count());

        config(['shop.cod.max_total' => null]);

        $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => '310.00',
            'payment_method' => 'cod',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }

    // --- Phone labels -----------------------------------------------------

    public function test_account_and_recipient_phones_are_labelled(): void
    {
        $order = $this->codOrder();

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertSee(['Account phone:', '011-111 1111', 'Recipient phone: 012-222 2222']);

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee('Recipient phone: 012-222 2222');

        $noPhone = $this->codOrder(customer: User::factory()->create(['phone' => null]));
        $this->actingAs($this->admin)->get(route('admin.orders.show', $noPhone))
            ->assertSee('Not set');

        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => Product::factory()->create()->id]);
        $this->get(route('checkout.create'))
            ->assertSee('Recipient phone')
            ->assertSee('Save this address to my address book');
    }
}
