<?php

namespace App\Services\Checkout;

use RuntimeException;

/**
 * The customer chose Cash on Delivery but CashOnDeliveryPolicy doesn't allow it.
 * The message is shown to the customer.
 */
class CashOnDeliveryNotAllowedException extends RuntimeException {}
