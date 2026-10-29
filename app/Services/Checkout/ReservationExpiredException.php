<?php

namespace App\Services\Checkout;

use RuntimeException;

/**
 * The customer's cart hold on one or more pairs ran out before the order was placed.
 */
class ReservationExpiredException extends RuntimeException
{
    /**
     * @param  array<int, string>  $items  product id => "Name (UK 8.5)"
     */
    public function __construct(public readonly array $items)
    {
        parent::__construct('Cart reservation expired for: '.implode(', ', $items));
    }
}
