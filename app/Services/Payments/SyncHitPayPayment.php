<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings an online order in line with HitPay's own record of its payment request
 * (GET /v1/payment-requests/{id}). The single place that turns HitPay's answer into
 * Paid / Failed. Used by the verified webhook (primary path), the order status page,
 * "Check again", and orders:expire-unpaid.
 *
 * Only HitPay's API response is trusted, never the browser's return URL. Both outcomes
 * lock and re-read the order (Order::recordGatewayPayment / markPaymentFailed), so the
 * webhook and a page refresh can't double-apply; whichever arrives first wins.
 */
class SyncHitPayPayment
{
    /** Page refreshes check HitPay at most once per this many seconds per order (API rate limits). */
    public const THROTTLE_SECONDS = 10;

    /** HitPay payment-request statuses after which it can no longer be paid. */
    private const FINISHED_UNPAID = ['failed', 'expired', 'canceled', 'inactive'];

    public function __construct(private HitPayClient $hitPay) {}

    /**
     * Fetch the order's payment request from HitPay and apply it.
     *
     * @return string one of apply()'s results, or 'skipped' (not an unpaid online order with a
     *                payment request), 'throttled' (checked < THROTTLE_SECONDS ago), 'unreachable'
     */
    public function refresh(Order $order, bool $throttle = false): string
    {
        if (! $order->isAwaitingOnlinePayment() || ! $order->gateway_reference) {
            return 'skipped';
        }

        if ($throttle && ! Cache::add("hitpay-sync:{$order->id}", true, self::THROTTLE_SECONDS)) {
            return 'throttled';
        }

        try {
            $paymentRequest = $this->hitPay->getPaymentRequest($order->gateway_reference);
        } catch (Throwable $e) {
            Log::warning('HitPay status check failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);

            return 'unreachable';
        }

        return $this->apply($order, $paymentRequest);
    }

    /**
     * Apply a payment request already fetched from HitPay's API.
     *
     * @param  array<string, mixed>  $paymentRequest
     * @return 'paid'|'already_paid'|'late'|'failed'|'pending'|'mismatch'|'unchanged'
     */
    public function apply(Order $order, array $paymentRequest): string
    {
        if ($order->payment_method !== PaymentMethod::Online
            || ($paymentRequest['reference_number'] ?? null) !== $order->order_number) {
            return 'mismatch';
        }

        if ($paymentId = HitPayClient::succeededPaymentId($paymentRequest, $order)) {
            $result = $order->recordGatewayPayment((string) $paymentRequest['id'], $paymentId);

            if ($result === 'late') {
                Log::warning('HitPay payment received for a cancelled/failed order: refund needed', ['order' => $order->order_number]);
            }

            return $result;
        }

        $status = $paymentRequest['status'] ?? null;

        if ($status === 'completed') {
            // Completed, but not for this order's amount/currency: never accept it.
            Log::error('HitPay payment request completed with a mismatching amount or currency', [
                'order' => $order->order_number,
                'amount' => $paymentRequest['amount'] ?? null,
                'currency' => $paymentRequest['currency'] ?? null,
            ]);

            return 'mismatch';
        }

        if (in_array($status, self::FINISHED_UNPAID, true)) {
            return $order->markPaymentFailed() ? 'failed' : 'unchanged';
        }

        return 'pending';
    }
}
