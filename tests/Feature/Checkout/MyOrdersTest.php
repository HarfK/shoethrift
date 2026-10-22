<?php

namespace Tests\Feature\Checkout;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $aisyah;

    private User $daniel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aisyah = User::factory()->create();
        $this->daniel = User::factory()->create();
    }

    private function orderFor(User $user, string $number, OrderStatus $status = OrderStatus::Pending, int $daysAgo = 0): Order
    {
        $order = Order::factory()->for($user)->status($status)->create([
            'order_number' => $number,
            'total' => 310,
            'shipping_fee' => 10,
            'created_at' => now()->subDays($daysAgo),
        ]);
        OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => "Shoe for {$number}"]))->create(['price' => 300]);

        return $order;
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('account.orders.index'))->assertRedirect(route('login'));
        $this->get(route('account.orders.show', 'TS-20261022-AAAAAA'))->assertRedirect(route('login'));
    }

    public function test_customers_see_only_their_own_orders_newest_first(): void
    {
        $this->orderFor($this->aisyah, 'TS-20261020-OLDERR', daysAgo: 2);
        $this->orderFor($this->aisyah, 'TS-20261022-NEWERR');
        $this->orderFor($this->daniel, 'TS-20261022-DANIEL');

        $this->actingAs($this->aisyah)->get(route('account.orders.index'))
            ->assertOk()
            ->assertSeeInOrder(['TS-20261022-NEWERR', 'TS-20261020-OLDERR'])
            ->assertSee(['RM 310.00', '1 item', 'Pending'])
            ->assertDontSee('TS-20261022-DANIEL');
    }

    public function test_empty_state(): void
    {
        $this->actingAs($this->aisyah)->get(route('account.orders.index'))
            ->assertSee("You haven't placed any orders yet.", false);
    }

    public function test_order_detail_shows_items_totals_address_and_progress(): void
    {
        $order = $this->orderFor($this->aisyah, 'TS-20261022-SHIPPD', OrderStatus::Shipped);

        $this->actingAs($this->aisyah)->get(route('account.orders.show', $order->order_number))
            ->assertOk()
            ->assertSee(['TS-20261022-SHIPPD', 'Shoe for TS-20261022-SHIPPD', 'RM 300.00', 'RM 10.00', 'RM 310.00'])
            ->assertSee([$order->shipping_name, $order->shipping_phone])
            ->assertSeeInOrder(['Placed', 'Paid', 'Shipped', 'Completed'])
            ->assertSee('aria-current="step"', false);
    }

    public function test_cancelled_orders_say_so_instead_of_progress(): void
    {
        $order = $this->orderFor($this->aisyah, 'TS-20261022-CANCEL', OrderStatus::Cancelled);

        $this->actingAs($this->aisyah)->get(route('account.orders.show', $order->order_number))
            ->assertSee('This order was cancelled.')
            ->assertDontSee('aria-label="Order progress"', false);
    }

    public function test_other_customers_orders_return_404_everywhere(): void
    {
        $order = $this->orderFor($this->daniel, 'TS-20261022-DANIEL');

        $this->actingAs($this->aisyah)->get(route('account.orders.show', $order->order_number))->assertNotFound();
        $this->actingAs($this->aisyah)->get(route('checkout.complete', $order->order_number))->assertNotFound();
        $this->actingAs($this->aisyah)->get(route('account.orders.show', 'TS-00000000-NOPE00'))->assertNotFound();
    }

    public function test_account_menu_links_to_my_orders(): void
    {
        $this->actingAs($this->aisyah)->get(route('home'))
            ->assertSee(route('account.orders.index'), false)
            ->assertSee('My orders');
    }

    public function test_orders_paginate_at_ten(): void
    {
        foreach (range(1, 12) as $i) {
            $this->orderFor($this->aisyah, sprintf('TS-20261022-PAGE%02d', $i));
        }

        $this->actingAs($this->aisyah)->get(route('account.orders.index'))
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 10 && $orders->total() === 12);
    }
}
