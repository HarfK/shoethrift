<?php

namespace App\Services\Checkout;

use RuntimeException;

/**
 * One or more cart items were sold, hidden or ran low on stock before the order could be placed.
 */
class ItemsUnavailableException extends RuntimeException
{
    /**
     * @param  array<int, string>  $items  product id => "Name (UK 8.5)"
     */
    public function __construct(public readonly array $items)
    {
        parent::__construct('Some items are no longer available: '.implode(', ', $items));
    }
}
