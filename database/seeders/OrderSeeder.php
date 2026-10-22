<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
            $createdAt = Carbon::instance(fake()->dateTimeBetween('-60 days'));

            // Keep payment data consistent with the status rules: "paid" only exists for online
            // orders, and seeded pending orders are COD (an old unpaid online order would just expire).
            $method = match ($status) {
                OrderStatus::Paid, OrderStatus::Failed => PaymentMethod::Online,
                OrderStatus::Pending => PaymentMethod::CashOnDelivery,
                default => fake()->randomElement(PaymentMethod::cases()),
            };
            $isPaid = $method === PaymentMethod::Online
                ? in_array($status, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Completed], true)
                : $status === OrderStatus::Completed;

            $order = Order::factory()
                ->for($customer)
                ->status($status)
                ->create([
                    'shipping_name' => $customer->name,
                    'shipping_phone' => $customer->phone,
                    'shipping_address' => $customer->address,
                    'shipping_fee' => config('shop.shipping_fee'),
                    'payment_method' => $method,
                    'gateway_reference' => $method === PaymentMethod::Online ? (string) Str::uuid() : null,
                    'payment_expires_at' => $method === PaymentMethod::Online ? $createdAt->copy()->addMinutes(30) : null,
                    'paid_at' => $isPaid ? $createdAt->copy()->addMinutes(fake()->numberBetween(2, 25)) : null,
                    'created_at' => $createdAt,
                ]);

            foreach ($products as $product) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => $product->price,
                ]);

                if (! in_array($status, [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
                    $product->decrement('stock');

                    if ($product->stock === 0) {
                        $product->update(['status' => ProductStatus::Sold]);
                    }
                }
            }

            $order->update(['total' => $products->sum('price') + config('shop.shipping_fee')]);
        }
    }
}
