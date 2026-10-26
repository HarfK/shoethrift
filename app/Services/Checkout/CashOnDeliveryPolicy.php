<?php

namespace App\Services\Checkout;

use App\Models\User;

/**
 * Who may pay by Cash on Delivery (config/shop.php 'cod'):
 * - customers with fewer than max_failed_deliveries failed COD deliveries, and
 * - orders up to max_total RM, when that limit is set.
 *
 * Checkout uses it to grey out the option; PlaceOrder enforces it again inside its transaction.
 */
class CashOnDeliveryPolicy
{
    /**
     * Why COD can't be used for this customer and order total, or null if it can.
     */
    public function unavailableReason(User $user, float|string $total): ?string
    {
        $failed = $user->failedCodCount();

        if ($failed >= config('shop.cod.max_failed_deliveries')) {
            return "Cash on Delivery isn't available on your account because of {$failed} failed "
                .str('delivery')->plural($failed).'. Please pay online.';
        }

        $maxTotal = config('shop.cod.max_total');

        if ($maxTotal !== null && PlaceOrder::toSen($total) > PlaceOrder::toSen($maxTotal)) {
            return 'Cash on Delivery is only available for orders up to RM '.number_format((float) $maxTotal, 2).'. Please pay online.';
        }

        return null;
    }
}
