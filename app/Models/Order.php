<?php

namespace App\Models;

use App\Enums\OrderStatus;
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
        ];
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
     * Move the order to a new status, enforcing OrderStatus::allowedTransitions().
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

            if (! $current->canTransitionTo($status)) {
                throw new DomainException("Order {$this->order_number} can't change from {$current->label()} to {$status->label()}.");
            }

            if ($status === OrderStatus::Cancelled) {
                foreach ($this->items()->get() as $item) {
                    $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                    $product->stock += $item->quantity;

                    if ($product->status === ProductStatus::Sold) {
                        $product->status = ProductStatus::Available;
                    }

                    $product->save();
                }
            }

            $this->forceFill(['status' => $status])->save();
        });
    }
}
