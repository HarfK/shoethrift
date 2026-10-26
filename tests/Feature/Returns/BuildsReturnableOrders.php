<?php

namespace Tests\Feature\Returns;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

/**
 * A delivered, paid online order (RM 300 Dunk + RM 10 shipping = RM 310), delivered 2 days ago.
 */
trait BuildsReturnableOrders
{
    private User $admin;

    private User $customer;

    private Product $dunk;

    private Order $order;

    private function setUpReturnableOrder(): void
    {
        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create(['name' => 'Aisyah Rahman']);
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'size' => 8.5, 'price' => 300, 'stock' => 0, 'status' => ProductStatus::Sold]);
        $this->order = $this->returnableOrder(product: $this->dunk);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function returnableOrder(array $attributes = [], ?Product $product = null): Order
    {
        $order = Order::factory()->for($this->customer)->create([
            'status' => OrderStatus::Completed,
            'payment_method' => PaymentMethod::Online,
            'gateway_reference' => 'pr-1',
            'gateway_payment_id' => 'pay-9a934d94',
            'total' => 310,
            'shipping_fee' => 10,
            'paid_at' => now()->subDays(4),
            'delivered_at' => now()->subDays(2),
            ...$attributes,
        ]);
        OrderItem::factory()->for($order)->for($product ?? Product::factory()->create(['stock' => 0, 'status' => ProductStatus::Sold]))
            ->create(['price' => 300, 'quantity' => 1]);

        return $order->load('items');
    }
}
