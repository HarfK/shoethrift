<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * An order for one sold-out product, as checkout would leave it.
     */
    private function orderWithSoldProduct(OrderStatus $status = OrderStatus::Paid, int $quantity = 1): array
    {
        $product = Product::factory()->sold()->create(['price' => 120]);
        $order = Order::factory()->status($status)->create(['total' => 120 * $quantity]);
        OrderItem::factory()->for($order)->for($product)->create(['quantity' => $quantity, 'price' => 120]);

        return [$order, $product];
    }

    private function changeStatus(Order $order, OrderStatus|string $status)
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.orders.show', $order))
            ->patch(route('admin.orders.status', $order), ['status' => $status instanceof OrderStatus ? $status->value : $status]);
    }

    // --- Access -----------------------------------------------------------

    public function test_customers_cannot_manage_orders(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create();

        $this->actingAs($customer)->get(route('admin.orders.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.orders.show', $order))->assertForbidden();
        $this->actingAs($customer)->patch(route('admin.orders.status', $order), ['status' => 'paid'])->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // --- List -------------------------------------------------------------

    public function test_index_searches_by_order_number_and_customer_and_filters_by_status(): void
    {
        $aisyah = User::factory()->create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com']);
        $daniel = User::factory()->create(['name' => 'Daniel Lim', 'email' => 'daniel@example.com']);
        Order::factory()->for($aisyah)->create(['order_number' => 'TS-20261001-AAAAAA']);
        Order::factory()->for($daniel)->status(OrderStatus::Shipped)->create(['order_number' => 'TS-20261002-BBBBBB']);

        $this->actingAs($this->admin)->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee(['TS-20261001-AAAAAA', 'TS-20261002-BBBBBB', 'Aisyah Rahman', 'daniel@example.com']);

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['search' => 'BBBBBB']))
            ->assertSee('TS-20261002-BBBBBB')
            ->assertDontSee('TS-20261001-AAAAAA');

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['search' => 'aisyah']))
            ->assertSee('TS-20261001-AAAAAA')
            ->assertDontSee('TS-20261002-BBBBBB');

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['search' => 'daniel@example']))
            ->assertSee('TS-20261002-BBBBBB')
            ->assertDontSee('TS-20261001-AAAAAA');

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['status' => 'shipped']))
            ->assertSee('TS-20261002-BBBBBB')
            ->assertDontSee('TS-20261001-AAAAAA');
    }

    public function test_index_paginates(): void
    {
        Order::factory(20)->create();

        $this->actingAs($this->admin)->get(route('admin.orders.index'))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 15 && $orders->total() === 20);
    }

    // --- Detail -----------------------------------------------------------

    public function test_detail_page_shows_customer_address_items_and_total(): void
    {
        $customer = User::factory()->create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'phone' => '012-345 6789']);
        $shoe = Product::factory()->create(['name' => 'Nike Dunk Low', 'brand' => 'Nike', 'size' => 8.5]);
        $order = Order::factory()->for($customer)->create([
            'order_number' => 'TS-20261003-CCCCCC',
            'shipping_address' => "12 Jalan Bunga\n40100 Shah Alam",
            'total' => 250,
        ]);
        OrderItem::factory()->for($order)->for($shoe)->create(['quantity' => 2, 'price' => 125]);

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee(['TS-20261003-CCCCCC', 'Aisyah Rahman', 'aisyah@example.com', '012-345 6789'])
            ->assertSee(['12 Jalan Bunga', '40100 Shah Alam'])
            ->assertSee(['Nike Dunk Low', 'UK 8.5', 'RM 125.00', 'RM 250.00']);
    }

    public function test_detail_page_only_offers_allowed_status_changes(): void
    {
        $paid = Order::factory()->status(OrderStatus::Paid)->create();
        $completed = Order::factory()->status(OrderStatus::Completed)->create();

        $this->actingAs($this->admin)->get(route('admin.orders.show', $paid))
            ->assertSee('<option value="shipped"', false)
            ->assertSee('<option value="cancelled"', false)
            ->assertDontSee('<option value="pending"', false);

        $this->actingAs($this->admin)->get(route('admin.orders.show', $completed))
            ->assertSee('can no longer be changed')
            ->assertDontSee('Update status');
    }

    // --- Status changes ---------------------------------------------------

    /**
     * @return array<string, array{OrderStatus, OrderStatus}>
     */
    public static function allowedTransitions(): array
    {
        return [
            'pending to paid' => [OrderStatus::Pending, OrderStatus::Paid],
            'paid to shipped' => [OrderStatus::Paid, OrderStatus::Shipped],
            'shipped to completed' => [OrderStatus::Shipped, OrderStatus::Completed],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_admin_can_move_an_order_forward(OrderStatus $from, OrderStatus $to): void
    {
        $order = Order::factory()->status($from)->create();

        $this->changeStatus($order, $to)
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('success');

        $this->assertSame($to, $order->fresh()->status);
    }

    /**
     * @return array<string, array{OrderStatus, OrderStatus}>
     */
    public static function forbiddenTransitions(): array
    {
        return [
            'backwards' => [OrderStatus::Shipped, OrderStatus::Paid],
            'skipping a step' => [OrderStatus::Pending, OrderStatus::Shipped],
            'cancel after shipping' => [OrderStatus::Shipped, OrderStatus::Cancelled],
            'reopen completed' => [OrderStatus::Completed, OrderStatus::Shipped],
            'reopen cancelled' => [OrderStatus::Cancelled, OrderStatus::Pending],
            'same status' => [OrderStatus::Paid, OrderStatus::Paid],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_disallowed_status_changes_are_rejected(OrderStatus $from, OrderStatus $to): void
    {
        $order = Order::factory()->status($from)->create();

        $this->changeStatus($order, $to)
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('error', "Order {$order->order_number} can't change from {$from->label()} to {$to->label()}.");

        $this->assertSame($from, $order->fresh()->status);
    }

    public function test_unknown_status_values_fail_validation(): void
    {
        $order = Order::factory()->create();

        $this->changeStatus($order, 'refunded')->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // --- Cancelling -------------------------------------------------------

    public function test_cancelling_returns_stock_and_makes_sold_products_available(): void
    {
        [$order, $product] = $this->orderWithSoldProduct(OrderStatus::Paid, quantity: 2);

        $this->changeStatus($order, OrderStatus::Cancelled)
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('success', "Order {$order->order_number} was cancelled and its items were returned to stock.");

        $product->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(2, $product->stock);
        $this->assertSame(ProductStatus::Available, $product->status);
        $this->assertTrue(Product::available()->whereKey($product->id)->exists());
    }

    public function test_cancelling_keeps_hidden_products_hidden_but_returns_their_stock(): void
    {
        $product = Product::factory()->hidden()->create(['stock' => 0]);
        $order = Order::factory()->status(OrderStatus::Pending)->create();
        OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1]);

        $this->changeStatus($order, OrderStatus::Cancelled)->assertSessionHas('success');

        $product->refresh();
        $this->assertSame(1, $product->stock);
        $this->assertSame(ProductStatus::Hidden, $product->status);
    }

    public function test_an_order_cannot_be_cancelled_twice(): void
    {
        [$order, $product] = $this->orderWithSoldProduct(OrderStatus::Pending);

        $this->changeStatus($order, OrderStatus::Cancelled)->assertSessionHas('success');
        $this->changeStatus($order, OrderStatus::Cancelled)->assertSessionHas('error');

        $this->assertSame(1, $product->fresh()->stock, 'Stock must only be returned once.');
    }

    public function test_cancelling_a_shipped_order_is_blocked_and_stock_is_untouched(): void
    {
        [$order, $product] = $this->orderWithSoldProduct(OrderStatus::Shipped);

        $this->changeStatus($order, OrderStatus::Cancelled)->assertSessionHas('error');

        $product->refresh();
        $this->assertSame(0, $product->stock);
        $this->assertSame(ProductStatus::Sold, $product->status);
    }
}
