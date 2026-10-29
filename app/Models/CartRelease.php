<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cart hold that ended without an order, so the customer can be told exactly why (shown once,
 * then notified_at is set) and admins can see customers whose holds keep expiring.
 */
class CartRelease extends Model
{
    public const EXPIRED = 'expired';

    public const BOUGHT_BY_OTHER = 'bought_by_other';

    public const NO_LONGER_FOR_SALE = 'no_longer_for_sale';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'product_id',
        'product_name',
        'reason',
        'quantity',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'released_at' => 'datetime',
            'notified_at' => 'datetime',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What the customer is told.
     */
    public function message(): string
    {
        return match ($this->reason) {
            self::BOUGHT_BY_OTHER => "This pair was bought by another customer: {$this->product_name}. We removed it from your cart.",
            self::NO_LONGER_FOR_SALE => "{$this->product_name} is no longer for sale and was removed from your cart.",
            default => "Your reservation for {$this->product_name} expired, so we removed it from your cart.",
        };
    }
}
