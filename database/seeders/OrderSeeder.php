<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Address;
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
                OrderStatus::Pending, OrderStatus::Delivered => PaymentMethod::CashOnDelivery,
                default => fake()->randomElement(PaymentMethod::cases()),
            };
            $isPaid = $method === PaymentMethod::Online
                ? in_array($status, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Completed], true)
                : $status === OrderStatus::Completed;

            $order = Order::factory()
                ->for($customer)
                ->status($status)
                ->create([
                    ...self::shippingFor($customer),
                    'shipping_fee' => config('shop.shipping_fee'),
                    'payment_method' => $method,
                    'gateway_reference' => $method === PaymentMethod::Online ? (string) Str::uuid() : null,
                    'payment_expires_at' => $method === PaymentMethod::Online ? $createdAt->copy()->addMinutes(30) : null,
                    'paid_at' => $isPaid ? $createdAt->copy()->addMinutes(fake()->numberBetween(2, 25)) : null,
                    'delivered_at' => in_array($status, [OrderStatus::Delivered, OrderStatus::Completed], true) ? $createdAt->copy()->addDays(3) : null,
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

        $this->seedCodBlockedCustomer();
        $this->seedReturnableOrder();
    }

    /**
     * A paid online order delivered yesterday for customer@thriftstore.test, so the
     * "Request a return" flow can be tried straight after seeding.
     */
    private function seedReturnableOrder(): void
    {
        $customer = User::where('email', 'customer@thriftstore.test')->first();
        $product = Product::available()->where('stock', 1)->inRandomOrder()->first();

        if (! $customer || ! $product) {
            return;
        }

        $order = Order::factory()->for($customer)->status(OrderStatus::Completed)->create([
            ...self::shippingFor($customer),
            'shipping_fee' => config('shop.shipping_fee'),
            'total' => $product->price + config('shop.shipping_fee'),
            'payment_method' => PaymentMethod::Online,
            'gateway_reference' => (string) Str::uuid(),
            'gateway_payment_id' => (string) Str::uuid(),
            'paid_at' => now()->subDays(4),
            'delivered_at' => now()->subDay(),
            'created_at' => now()->subDays(4),
        ]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => $product->price]);
        $product->update(['stock' => 0, 'status' => ProductStatus::Sold]);
    }

    /**
     * The order's snapshot of the customer's default address (see Address::toShippingSnapshot()).
     *
     * @return array{shipping_name: string, shipping_phone: string, shipping_address: string}
     */
    private static function shippingFor(User $customer): array
    {
        $address = $customer->defaultAddress ?? Address::factory()->for($customer)->default()->create([
            'recipient_name' => $customer->name,
            'phone' => $customer->phone,
        ]);

        return $address->toShippingSnapshot();
    }

    /**
     * A customer with 2 failed Cash on Delivery deliveries, so admins can see the count
     * and checkout shows COD as blocked (log in as cod.blocked@thriftstore.test / password).
     * Failed deliveries were cancelled, so their stock is untouched.
     */
    private function seedCodBlockedCustomer(): void
    {
        $customer = User::factory()->create([
            'name' => 'Faizal Rahim',
            'email' => 'cod.blocked@thriftstore.test',
        ]);

        foreach ([20, 6] as $daysAgo) {
            $product = Product::available()->inRandomOrder()->first();
            if (! $product) {
                return;
            }

            $createdAt = now()->subDays($daysAgo);
            $order = Order::factory()->for($customer)->cashOnDelivery()->status(OrderStatus::Cancelled)->create([
                ...self::shippingFor($customer),
                'shipping_fee' => config('shop.shipping_fee'),
                'total' => $product->price + config('shop.shipping_fee'),
                'delivery_failed_at' => $createdAt->copy()->addDays(3),
                'created_at' => $createdAt,
            ]);
            $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => $product->price]);
        }
    }
}
