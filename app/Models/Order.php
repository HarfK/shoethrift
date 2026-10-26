<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReturnStatus;
use App\Services\AdminNotifier;
use Database\Factories\OrderFactory;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
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
        'delivery_failed_at',
        'delivered_at',
        'refunded_amount',
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
            'delivery_failed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'refunded_amount' => 'decimal:2',
        ];
    }

    /**
     * Status changes an admin may make from the given status, which depend on how the order is paid:
     *
     * - Online (HitPay): pending → paid happens ONLY via the verified webhook, so admins can just
     *   cancel a pending order; then paid → shipped → completed.
     * - Cash on Delivery: pending → shipped → delivered → completed (= cash collected). Cancelling a
     *   pending order is a shop cancel; cancelling a shipped/delivered one is a FAILED DELIVERY
     *   (recorded in delivery_failed_at and counted against the customer, see CashOnDeliveryPolicy).
     * - Orders from before payments existed (null): OrderStatus's generic forward-only rules.
     *
     * @return list<OrderStatus>
     */
    public function allowedTransitionsFrom(OrderStatus $status): array
    {
        return match ($this->payment_method) {
            PaymentMethod::Online => match ($status) {
                OrderStatus::Pending => [OrderStatus::Cancelled],
                OrderStatus::Delivered => [],
                default => $status->allowedTransitions(),
            },
            PaymentMethod::CashOnDelivery => match ($status) {
                OrderStatus::Pending => [OrderStatus::Shipped, OrderStatus::Cancelled],
                OrderStatus::Shipped => [OrderStatus::Delivered, OrderStatus::Cancelled],
                OrderStatus::Delivered => [OrderStatus::Completed, OrderStatus::Cancelled],
                default => [],
            },
            null => $status->allowedTransitions(),
        };
    }

    public function isCashOnDelivery(): bool
    {
        return $this->payment_method === PaymentMethod::CashOnDelivery;
    }

    /**
     * A COD order cancelled because the parcel couldn't be delivered or the cash wasn't paid.
     */
    public function isFailedDelivery(): bool
    {
        return $this->delivery_failed_at !== null;
    }

    /**
     * Status as shown to people: COD's completed step is "Cash collected", and a failed
     * delivery is "Failed delivery" rather than a plain "Cancelled".
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isFailedDelivery() => 'Failed delivery',
            $this->isPartiallyRefunded() => 'Partially refunded',
            $this->isCashOnDelivery() && $this->status === OrderStatus::Completed => 'Cash collected',
            default => $this->status->label(),
        };
    }

    /**
     * Button text for moving this order (in its current status) to $to.
     */
    public function actionLabel(OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Paid => 'Mark paid',
            OrderStatus::Shipped => 'Mark shipped',
            OrderStatus::Delivered => 'Mark delivered',
            OrderStatus::Completed => $this->isCashOnDelivery() ? 'Cash collected' : 'Mark completed',
            OrderStatus::Cancelled => $this->cancellingMeansFailedDelivery() ? 'Failed delivery' : 'Cancel order',
            default => $to->label(),
        };
    }

    /**
     * Cancelling a COD order after it left the shop means the delivery failed.
     */
    public function cancellingMeansFailedDelivery(?OrderStatus $from = null): bool
    {
        return $this->isCashOnDelivery()
            && in_array($from ?? $this->status, [OrderStatus::Shipped, OrderStatus::Delivered], true);
    }

    /**
     * Orders that count as revenue: money actually received (paid_at set) and not cancelled or
     * failed. Online: paid/shipped/completed; COD: completed (cash collected). Refunded orders are
     * included so their refunds can be subtracted (see App\Services\Reports\SalesReport).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeRevenue(Builder $query): void
    {
        $query->whereNotNull('paid_at')
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Completed, OrderStatus::Refunded]);
    }

    /**
     * COD orders whose delivery failed (for the per-customer count).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeFailedCodDelivery(Builder $query): void
    {
        $query->where('payment_method', PaymentMethod::CashOnDelivery)->whereNotNull('delivery_failed_at');
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

            // The date part is the Malaysian date, so an order placed at 1 am in KL isn't dated yesterday.
            $number = 'TS-'.now(config('app.display_timezone'))->format('Ymd').'-'.$suffix;
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
     * again (hidden products stay hidden); for a COD order that had shipped it also records
     * delivery_failed_at. The order row is locked and re-read inside the transaction, so two
     * concurrent cancels can't restock twice.
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

                if ($this->cancellingMeansFailedDelivery($current)) {
                    $this->delivery_failed_at = now();
                }
            }

            $this->forceFill(['status' => $status]);

            if ($status === OrderStatus::Completed && $this->payment_method === PaymentMethod::CashOnDelivery && $this->paid_at === null) {
                $this->paid_at = now();
            }

            // Delivered starts the return window: COD has its own step; online "completed" means delivered.
            if (in_array($status, [OrderStatus::Delivered, OrderStatus::Completed], true) && $this->delivered_at === null) {
                $this->delivered_at = now();
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
            AdminNotifier::paymentFailed($order); // sent after commit
            $this->setRawAttributes($order->getAttributes(), true);

            return true;
        });
    }

    /**
     * Return each item's quantity to stock (see Product::returnToStock()).
     * Call inside a transaction that has locked this order.
     */
    private function restoreStock(): void
    {
        foreach ($this->items()->get() as $item) {
            Product::returnToStock($item->product_id, $item->quantity);
        }
    }

    /**
     * Paid orders get a receipt (online paid → shipped → completed, or completed COD).
     */
    public function hasReceipt(): bool
    {
        return $this->paid_at !== null
            && in_array($this->status, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Completed, OrderStatus::Refunded], true);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Orders whose buyer may review the pairs: paid online orders (paid, shipped, completed)
     * and COD orders once delivered (delivered, completed). Never pending, cancelled, failed
     * or refunded orders.
     */
    public function allowsReviews(): bool
    {
        return match ($this->status) {
            OrderStatus::Paid, OrderStatus::Shipped => $this->paid_at !== null,
            OrderStatus::Delivered => true,
            OrderStatus::Completed => true,
            default => false,
        };
    }

    /**
     * Items the customer may still review: the order allows reviews, the pair wasn't returned
     * and refunded, and it has no review yet (one per product per order).
     *
     * @return Collection<int, OrderItem>
     */
    public function reviewableItems(): Collection
    {
        if (! $this->allowsReviews()) {
            return new Collection;
        }

        $this->loadMissing(['items', 'reviews', 'returnRequest.items']);

        $refundedItemIds = $this->returnRequest?->status === ReturnStatus::Refunded
            ? $this->returnRequest->items->pluck('order_item_id')->all()
            : [];
        $reviewedProductIds = $this->reviews->pluck('product_id')->all();

        return $this->items
            ->reject(fn (OrderItem $item) => in_array($item->id, $refundedItemIds, true)
                || in_array($item->product_id, $reviewedProductIds, true))
            ->values();
    }

    /**
     * @return HasOne<ReturnRequest, $this>
     */
    public function returnRequest(): HasOne
    {
        return $this->hasOne(ReturnRequest::class);
    }

    /**
     * Some, but not all, of the paid amount was refunded.
     */
    public function isPartiallyRefunded(): bool
    {
        return (float) $this->refunded_amount > 0 && $this->status !== OrderStatus::Refunded;
    }

    /**
     * Amount still refundable: what was paid minus what was already refunded.
     */
    public function refundableAmount(): string
    {
        return number_format(max(0, (float) $this->total - (float) $this->refunded_amount), 2, '.', '');
    }

    /**
     * Last moment a return can be requested: return window (config/shop.php) after delivery.
     */
    public function returnDeadline(): ?Carbon
    {
        return $this->delivered_at?->copy()->addDays((int) config('shop.returns.window_days'));
    }

    /**
     * Why this order can't be returned right now, or null if it can. Returns need an order that
     * is paid AND delivered (status completed: online delivered, or COD cash collected), inside
     * the return window, with no earlier return request.
     */
    public function returnUnavailableReason(): ?string
    {
        return match (true) {
            $this->returnRequest()->exists() => 'A return has already been requested for this order.',
            $this->status !== OrderStatus::Completed || $this->paid_at === null || $this->delivered_at === null => 'Returns are available once your order has been delivered and paid.',
            now()->greaterThan($this->returnDeadline()) => 'The '.config('shop.returns.window_days').'-day return window for this order has closed.',
            default => null,
        };
    }

    public function canRequestReturn(): bool
    {
        return $this->returnUnavailableReason() === null;
    }

    /**
     * Paid with the local-only "Simulate payment success" test tool, not a real payment.
     */
    public function isSimulatedPayment(): bool
    {
        return str_starts_with((string) $this->gateway_payment_id, 'SIMULATED-');
    }
}
