<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Carbon;

final readonly class CartLine
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public ?Carbon $reservedUntil = null,
    ) {}

    /**
     * Uses the product's current price; the price is only fixed when an order is placed.
     */
    public function total(): float
    {
        return (float) $this->product->price * $this->quantity;
    }
}
