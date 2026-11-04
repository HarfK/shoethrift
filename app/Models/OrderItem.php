<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Services\Recommendations\Interactions;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * Recommendations: a Cash on Delivery order counts as a purchase when it is placed.
     */
    protected static function booted(): void
    {
        static::created(function (OrderItem $item) {
            $order = $item->order;
            if ($order?->payment_method === PaymentMethod::CashOnDelivery) {
                Interactions::purchase($order->user_id, $item->product_id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
