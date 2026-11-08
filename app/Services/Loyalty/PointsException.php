<?php

namespace App\Services\Loyalty;

use RuntimeException;

/**
 * A points change that isn't allowed (not enough points, outside the redeem rules). The message is customer-readable.
 */
class PointsException extends RuntimeException {}
