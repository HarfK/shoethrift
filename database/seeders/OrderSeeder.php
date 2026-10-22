<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Seed customer orders built from available products.
     *
     * Each order's total is the sum of its items. Products in non-cancelled
     * orders have their stock reduced and are marked sold when it hits zero.
     */
    public function run(): void
    {
        $customers = User::where('role', UserRole::Customer)->get();

        for ($i = 0; $i < 15; $i++) {
            $products = Product::available()->inRandomOrder()->limit(fake()->numberBetween(1, 2))->get();

            if ($products->isEmpty()) {
                break;
            }

            $customer = $customers->random();
            $status = fake()->randomElement(OrderStatus::cases());

            $order = Order::factory()
                ->for($customer)
                ->status($status)
                ->create([
                    'shipping_address' => $customer->address,
                    'created_at' => fake()->dateTimeBetween('-60 days'),
                ]);

            foreach ($products as $product) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => $product->price,
                ]);

                if ($status !== OrderStatus::Cancelled) {
                    $product->decrement('stock');

                    if ($product->stock === 0) {
                        $product->update(['status' => ProductStatus::Sold]);
                    }
                }
            }

            $order->update(['total' => $products->sum('price')]);
        }
    }
}
