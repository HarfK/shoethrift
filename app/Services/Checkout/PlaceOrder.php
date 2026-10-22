<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart into an order, atomically.
 *
 * Inside one transaction the cart's products are locked (SELECT ... FOR UPDATE, in id order so
 * two checkouts can't deadlock), re-checked, priced from the locked rows, and their stock is
 * reduced. A second customer buying the same one-of-a-kind pair waits on the lock and then sees
 * it as sold. Any failure rolls everything back: no order, no stock change.
 *
 * Prices and totals are never taken from the request; the total the customer saw is only
 * compared, so a price change can't slip through unnoticed.
 */
class PlaceOrder
{
    /**
     * @param  array<int, int>  $quantities  product id => quantity (from ShoppingCart::quantities())
     * @param  array{shipping_name: string, shipping_phone: string, shipping_address: string}  $shipping
     *
     * @throws ItemsUnavailableException
     * @throws PriceChangedException
     */
    public function handle(User $user, array $quantities, array $shipping, string $expectedTotal): Order
    {
        return DB::transaction(function () use ($user, $quantities, $shipping, $expectedTotal) {
            $products = Product::query()
                ->whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $unavailable = [];
            foreach ($quantities as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product?->isAvailable() || $product->stock < $quantity) {
                    $unavailable[$productId] = $product ? "{$product->name} ({$product->size_label})" : 'An item in your cart';
                }
            }

            if ($unavailable !== []) {
                throw new ItemsUnavailableException($unavailable);
            }

            // Work in sen (cents) to avoid floating-point rounding.
            $subtotalSen = 0;
            foreach ($quantities as $productId => $quantity) {
                $subtotalSen += self::toSen($products[$productId]->price) * $quantity;
            }
            $shippingSen = self::toSen(config('shop.shipping_fee'));
            $totalSen = $subtotalSen + $shippingSen;

            if (self::toSen($expectedTotal) !== $totalSen) {
                throw new PriceChangedException(self::fromSen($totalSen));
            }

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'status' => OrderStatus::Pending,
                'total' => self::fromSen($totalSen),
                'shipping_fee' => self::fromSen($shippingSen),
                ...$shipping,
            ]);

            foreach ($quantities as $productId => $quantity) {
                $product = $products[$productId];

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price' => $product->price,
                ]);

                $product->stock -= $quantity;
                if ($product->stock === 0) {
                    $product->status = ProductStatus::Sold;
                }
                $product->save();
            }

            return $order;
        });
    }

    public static function toSen(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function fromSen(int $sen): string
    {
        return number_format($sen / 100, 2, '.', '');
    }
}
