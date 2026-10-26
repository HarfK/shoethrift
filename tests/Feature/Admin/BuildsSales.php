<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Carbon;

trait BuildsSales
{
    /**
     * An order with one item (price + RM 10 shipping), paid at $paidAt (UTC) unless null.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sale(float $price, ?string $paidAtUtc, array $attributes = [], ?Product $product = null, int $quantity = 1): Order
    {
        $order = Order::factory()->create([
            'status' => OrderStatus::Completed,
            'payment_method' => PaymentMethod::Online,
            'total' => $price * $quantity + 10,
            'shipping_fee' => 10,
            'paid_at' => $paidAtUtc ? Carbon::parse($paidAtUtc, 'UTC') : null,
            'created_at' => $paidAtUtc ? Carbon::parse($paidAtUtc, 'UTC') : now(),
            ...$attributes,
        ]);

        OrderItem::factory()->for($order)->for($product ?? Product::factory()->create(['price' => $price]))
            ->create(['price' => $price, 'quantity' => $quantity]);

        return $order;
    }

    private function product(string $name, string $brand, string $category, float $price = 100, int $stock = 0): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'brand' => $brand,
            'price' => $price,
            'stock' => $stock,
            'status' => $stock > 0 ? ProductStatus::Available : ProductStatus::Sold, // as checkout leaves it
            'category_id' => Category::firstOrCreate(['name' => $category], ['slug' => str($category)->slug()])->id,
        ]);
    }
}
