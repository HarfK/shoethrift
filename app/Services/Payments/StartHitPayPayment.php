<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Get a HitPay checkout URL for an online order that is still awaiting payment, creating the
 * payment request on first use and reusing it afterwards (so a customer never has two open links
 * for one order). Called after the order transaction has committed: an HTTP call must never
 * hold the stock locks.
 */
class StartHitPayPayment
{
    public function __construct(private HitPayClient $hitPay) {}

    /**
     * @return string|null the HitPay checkout URL, or null if HitPay couldn't be reached (logged)
     */
    public function handle(Order $order): ?string
    {
        if (! $order->isAwaitingOnlinePayment() || $order->payment_expires_at?->isPast()) {
            return null;
        }

        if ($order->payment_url) {
            return $order->payment_url;
        }

        try {
            $request = $this->hitPay->createPaymentRequest($order);
        } catch (Throwable $e) {
            Log::error('HitPay payment request failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);

            return null;
        }

        $order->forceFill(['gateway_reference' => $request['id'], 'payment_url' => $request['url']])->save();

        return $request['url'];
    }
}
