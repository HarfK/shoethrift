<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use Database\Factories\OrderFactory;
use DomainException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'order_number',
        'total',
        'shipping_fee',
        'status',
        'shipping_name',
        'shipping_phone',
        'shipping_address',
        'payment_method',
        'gateway_reference',
        'gateway_payment_id',
        'payment_url',
        'payment_expires_at',
        'paid_at',
    ];

    /**
     * Characters for order numbers: no 0/O or 1/I/L, so customers can read them out.
     */
    private const ORDER_NUMBER_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Status changes an admin may make from the given status, which depend on how the order is paid:
     *
     * - Online (HitPay): pending → paid happens ONLY via the verified webhook, so admins can just
     *   cancel a pending order; then paid → shipped → completed.
     * - Cash on Delivery: pending → shipped → completed (completed = cash collected); cancel only while pending.
     * - Orders from before payments existed (null): OrderStatus's generic forward-only rules.
     *
     * @return list<OrderStatus>
     */
    public function allowedTransitionsFrom(OrderStatus $status): array
    {
        return match ($this->payment_method) {
            PaymentMethod::Online => match ($status) {
                OrderStatus::Pending => [OrderStatus::Cancelled],
                default => $status->allowedTransitions(),
            },
            PaymentMethod::CashOnDelivery => match ($status) {
                OrderStatus::Pending => [OrderStatus::Shipped, OrderStatus::Cancelled],
                OrderStatus::Shipped => [OrderStatus::Completed],
                default => [],
            },
            null => $status->allowedTransitions(),
        };
    }

    /**
     * @return list<OrderStatus>
     */
    public function allowedTransitions(): array
    {
        return $this->allowedTransitionsFrom($this->status);
    }

    public function isAwaitingOnlinePayment(): bool
    {
        return $this->payment_method === PaymentMethod::Online
            && $this->status === OrderStatus::Pending
            && $this->paid_at === null;
    }

    /**
     * Money arrived (via HitPay) for an order that had already been cancelled or marked failed.
     */
    public function needsRefund(): bool
    {
        return in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Failed], true)
            && $this->paid_at !== null
            && $this->payment_method === PaymentMethod::Online;
    }

    /**
     * Record a payment confirmed by HitPay (verified webhook + server-side status check only).
     * Idempotent: the row is locked and re-read, and an order that already has paid_at is left alone.
     *
     * @return 'paid'|'already_paid'|'late' 'late' = the order was cancelled/failed first (refund needed)
     */
    public function recordGatewayPayment(string $paymentRequestId, ?string $paymentId): string
    {
        return DB::transaction(function () use ($paymentRequestId, $paymentId) {
            $order = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($order->paid_at !== null) {
                return 'already_paid';
            }

            $order->forceFill([
                'paid_at' => now(),
                'gateway_reference' => $paymentRequestId,
                'gateway_payment_id' => $paymentId,
            ]);

            $result = 'late';
            if ($order->status === OrderStatus::Pending) {
                $order->status = OrderStatus::Paid;
                $result = 'paid';
            }

            $order->save();
            $this->setRawAttributes($order->getAttributes(), true);

            return $result;
        });
    }

    /**
     * A new order number like "TS-20261022-K7M2QX", unique among existing orders
     * (the column is also unique-indexed as a backstop).
     */
    public static function generateOrderNumber(): string
    {
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::ORDER_NUMBER_ALPHABET[random_int(0, strlen(self::ORDER_NUMBER_ALPHABET) - 1)];
            }

            $number = 'TS-'.now()->format('Ymd').'-'.$suffix;
        } while (static::where('order_number', $number)->exists());

        return $number;
    }

    /**
     * Items total before shipping.
     *
     * @return Attribute<string, never>
     */
    protected function subtotal(): Attribute
    {
        return Attribute::get(fn () => number_format((float) $this->total - (float) $this->shipping_fee, 2, '.', ''));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'order_items')
            ->withPivot(['quantity', 'price'])
            ->withTimestamps();
    }

    /**
     * Move the order to a new status, enforcing allowedTransitionsFrom() (which depends on the payment method).
     * Completing a Cash on Delivery order records paid_at (the cash was collected).
     *
     * Cancelling returns each item's quantity to stock and makes sold products available
     * again (hidden products stay hidden). The order row is locked and re-read inside the
     * transaction, so two concurrent cancels can't restock twice.
     *
     * @throws DomainException when the transition isn't allowed
     */
    public function transitionTo(OrderStatus $status): void
    {
        DB::transaction(function () use ($status) {
            $current = static::query()->lockForUpdate()->findOrFail($this->id)->status;

            if (! in_array($status, $this->allowedTransitionsFrom($current), true)) {
                throw new DomainException("Order {$this->order_number} can't change from {$current->label()} to {$status->label()}.");
            }

            if ($status === OrderStatus::Cancelled) {
                $this->restoreStock();
            }

            $this->forceFill(['status' => $status]);

            if ($status === OrderStatus::Completed && $this->payment_method === PaymentMethod::CashOnDelivery && $this->paid_at === null) {
                $this->paid_at = now();
            }

            $this->save();
        });
    }

    /**
     * The online payment didn't go through: mark the order Failed and put its pairs back on sale.
     * Only acts on a still-pending, unpaid order (row locked and re-read), so it's safe to call
     * repeatedly and can't race the webhook: whichever runs first wins.
     *
     * @return bool whether the order was marked failed by this call
     */
    public function markPaymentFailed(): bool
    {
        return DB::transaction(function () {
            $order = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($order->status !== OrderStatus::Pending || $order->paid_at !== null) {
                $this->setRawAttributes($order->getAttributes(), true);

                return false;
            }

            $order->restoreStock();
            $order->status = OrderStatus::Failed;
            $order->save();
            $this->setRawAttributes($order->getAttributes(), true);

            return true;
        });
    }

    /**
     * Return each item's quantity to stock; sold products become available (hidden stay hidden).
     * Call inside a transaction that has locked this order.
     */
    private function restoreStock(): void
    {
        foreach ($this->items()->get() as $item) {
            $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
            $product->stock += $item->quantity;

            if ($product->status === ProductStatus::Sold) {
                $product->status = ProductStatus::Available;
            }

            $product->save();
        }
    }

    /**
     * Paid orders get a receipt (online paid → shipped → completed, or completed COD).
     */
    public function hasReceipt(): bool
    {
        return $this->paid_at !== null
            && in_array($this->status, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Completed], true);
    }

    /**
     * Paid with the local-only "Simulate payment success" test tool, not a real payment.
     */
    public function isSimulatedPayment(): bool
    {
        return str_starts_with((string) $this->gateway_payment_id, 'SIMULATED-');
    }
}
