<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a logged-in customer did with a product: view, cart or purchase.
 * Written only through App\Services\Recommendations\Interactions.
 */
class UserInteraction extends Model
{
    public const VIEW = 'view';

    public const CART = 'cart';

    public const PURCHASE = 'purchase';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'product_id',
        'type',
        'source',
    ];

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
}
