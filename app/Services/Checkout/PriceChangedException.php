<?php

namespace App\Services\Checkout;

use RuntimeException;

/**
 * The total the customer saw on the checkout page no longer matches the server's total.
 */
class PriceChangedException extends RuntimeException
{
    public function __construct(public readonly string $newTotal)
    {
        parent::__construct("The order total changed to RM {$newTotal}.");
    }
}
