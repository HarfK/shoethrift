<?php

namespace App\Services\Returns;

use RuntimeException;

/**
 * The refund couldn't be recorded. The message is shown to the admin.
 */
class RefundFailedException extends RuntimeException {}
