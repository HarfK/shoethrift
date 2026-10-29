<?php

namespace App\Services\Cart;

use RuntimeException;

/**
 * A pair couldn't be reserved (held by someone else, sold, or a cart limit). The message is
 * shown to the customer.
 */
class ReservationException extends RuntimeException {}
