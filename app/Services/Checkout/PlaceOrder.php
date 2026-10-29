<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotifier;
use App\Services\Cart\Reservations;
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
    public function __construct(private readonly CashOnDeliveryPolicy $codPolicy) {}

    /**
     * @param  array<int, int>  $quantities  product id => quantity (from ShoppingCart::quantities())
     * @param  array{shipping_name: string, shipping_phone: string, shipping_address: string}  $shipping
     *
     * @throws ItemsUnavailableException
     * @throws PriceChangedException
     * @throws CashOnDeliveryNotAllowedException
     * @throws ReservationExpiredException the customer's cart hold ran out
     */
    public function handle(User $user, array $quantities, array $shipping, string $expectedTotal, PaymentMethod $paymentMethod): Order
    {
        return DB::transaction(function () use ($user, $quantities, $shipping, $expectedTotal, $paymentMethod) {
            $products = Product::query()
                ->whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // The customer's own holds (cart lines) for these pairs, locked with the products.
            $holds = CartItem::query()
                ->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
                ->whereIn('product_id', array_keys($quantities))
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $unavailable = [];
            $expired = [];
            foreach ($quantities as $productId => $quantity) {
                $product = $products->get($productId);
                $label = $product ? "{$product->name} ({$product->size_label})" : 'An item in your cart';

                // Pairs held in other customers' carts can't be sold to this customer.
                if (! $product?->isAvailable() || $product->stock - Reservations::heldByOthers($productId, $user->id) < $quantity) {
                    $unavailable[$productId] = $label;
                } elseif (! $holds->get($productId)?->reserved_until?->isFuture()) {
                    $expired[$productId] = $label;
                }
            }

            if ($unavailable !== []) {
                throw new ItemsUnavailableException($unavailable);
            }
            if ($expired !== []) {
                throw new ReservationExpiredException($expired);
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

            if ($paymentMethod === PaymentMethod::CashOnDelivery
                && $reason = $this->codPolicy->unavailableReason($user, self::fromSen($totalSen))) {
                throw new CashOnDeliveryNotAllowedException($reason);
            }

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'status' => OrderStatus::Pending,
                'total' => self::fromSen($totalSen),
                'shipping_fee' => self::fromSen($shippingSen),
                'payment_method' => $paymentMethod,
                // Online orders hold their stock only for the payment window (see orders:expire-unpaid).
                'payment_expires_at' => $paymentMethod === PaymentMethod::Online
                    ? now()->addMinutes((int) config('services.hitpay.payment_window_minutes'))
                    : null,
                ...$shipping,
            ]);

            $lowStockThreshold = (int) config('shop.low_stock_threshold');

            foreach ($quantities as $productId => $quantity) {
                $product = $products[$productId];
                $stockBefore = $product->stock;

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

                // Only multi-pair products crossing the threshold; a single pair selling out is normal.
                if ($stockBefore > $lowStockThreshold && $product->stock >= 1 && $product->stock <= $lowStockThreshold) {
                    AdminNotifier::lowStock($product);
                }
            }

            // The cart holds become the order: the order now holds the stock (an online order keeps
            // it through its payment window; a failed payment returns it, see markPaymentFailed()).
            CartItem::query()->whereIn('id', $holds->pluck('id'))->delete();

            // Deferred until the transaction commits (see AdminNotifier).
            AdminNotifier::newOrder($order);

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
