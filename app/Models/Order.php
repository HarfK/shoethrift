<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use Database\Factories\OrderFactory;
use DomainException;
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
        'status',
        'shipping_address',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'status' => OrderStatus::class,
        ];
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
