<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Notify me when it's free": one per customer per product, deleted once the email is sent
 * (App\Services\Cart\ProductWatchers).
 */
class ProductWatch extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'product_id',
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
