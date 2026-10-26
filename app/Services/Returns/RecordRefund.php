<?php

namespace App\Services\Returns;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Services\Checkout\PlaceOrder;
use App\Services\Payments\HitPayClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

/**
 * Refund an approved return, fully or partly, and put the chosen pairs back on sale.
 *
 * HitPay refunds (POST /v1/refund) can't be undone and must never happen twice, so:
 * 1. the return row is locked and `refund_attempted_at` is set and COMMITTED before calling HitPay,
 *    which makes a second admin (or a double-click) stop at step 1;
 * 2. HitPay is called outside any transaction;
 * 3. on a definite rejection (4xx) the attempt is cleared, so the admin can retry or refund manually;
 *    with no clear answer (timeout, 5xx) it stays set, and a retry needs $retryConfirmed (the admin
 *    checked the HitPay dashboard first);
 * 4. on success the refund, order and stock are updated in one transaction.
 */
class RecordRefund
{
    public function __construct(private readonly HitPayClient $hitPay) {}

    /**
     * Whether this order can be refunded through the HitPay API (a real online payment).
     */
    public static function canUseHitPay(Order $order): bool
    {
        return $order->payment_method === PaymentMethod::Online
            && filled($order->gateway_payment_id)
            && ! $order->isSimulatedPayment();
    }

    /**
     * @param  list<int>  $restockItemIds  return_request_items ids to put back on sale
     *
     * @throws RefundFailedException
     */
    public function handle(
        ReturnRequest $return,
        string $amount,
        RefundMethod $method,
        ?string $manualReference,
        array $restockItemIds,
        bool $retryConfirmed = false,
    ): void {
        $amountSen = PlaceOrder::toSen($amount);
        $order = $return->order;

        if ($amountSen <= 0) {
            throw new RefundFailedException('Enter a refund amount above RM 0.00.');
        }
        if ($amountSen > PlaceOrder::toSen($order->refundableAmount())) {
            throw new RefundFailedException("The refund can't be more than RM {$order->refundableAmount()}, the amount paid and not yet refunded.");
        }
        if ($method === RefundMethod::HitPay && ! self::canUseHitPay($order)) {
            throw new RefundFailedException('This order has no HitPay payment to refund. Refund it manually and record the reference.');
        }
        if ($method === RefundMethod::Manual && blank($manualReference)) {
            throw new RefundFailedException('Enter a reference for the manual refund (e.g. bank transfer or cash receipt number).');
        }

        $this->claim($return, $method, $retryConfirmed);

        $reference = $method === RefundMethod::HitPay
            ? $this->refundThroughHitPay($return, $order, PlaceOrder::fromSen($amountSen))
            : trim((string) $manualReference);

        $this->record($return, PlaceOrder::fromSen($amountSen), $method, $reference, $restockItemIds);
    }

    /**
     * Lock the return, check it's refundable, and (for HitPay) commit the attempt marker.
     */
    private function claim(ReturnRequest $return, RefundMethod $method, bool $retryConfirmed): void
    {
        DB::transaction(function () use ($return, $method, $retryConfirmed) {
            $current = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);

            if ($current->status !== ReturnStatus::Approved) {
                throw new RefundFailedException("Only an approved return can be refunded. This one is {$current->status->label()}.");
            }

            if ($method === RefundMethod::HitPay) {
                if ($current->refund_attempted_at !== null && ! $retryConfirmed) {
                    throw new RefundFailedException('A HitPay refund was already attempted for this return and its result is unknown. Check the HitPay dashboard first: if the refund is there, record it manually with its reference; if not, tick "I checked HitPay" and try again.');
                }

                $current->forceFill(['refund_attempted_at' => now()])->save();
                $return->setRawAttributes($current->getAttributes(), true);
            }
        });
    }

    /**
     * @return string the HitPay refund id
     */
    private function refundThroughHitPay(ReturnRequest $return, Order $order, string $amount): string
    {
        try {
            $refund = $this->hitPay->refund($order->gateway_payment_id, $amount);
        } catch (RequestException $e) {
            if ($e->response->clientError()) {
                // HitPay said no: nothing was refunded, so the admin may try again.
                $return->forceFill(['refund_attempted_at' => null])->save();
                $message = $e->response->json('message') ?? $e->response->json('error') ?? 'request rejected';

                throw new RefundFailedException("HitPay didn't refund this payment ({$message}). You can try again or refund manually.");
            }

            throw new RefundFailedException("HitPay returned an error ({$e->response->status()}), so we can't tell whether the refund went through. Check the HitPay dashboard before trying again.");
        } catch (ConnectionException) {
            throw new RefundFailedException("We couldn't reach HitPay, so we can't tell whether the refund went through. Check the HitPay dashboard before trying again.");
        }

        if (($refund['status'] ?? null) !== 'succeeded' || blank($refund['id'] ?? null)) {
            $status = $refund['status'] ?? 'unknown';
            $id = $refund['id'] ?? 'no id';

            throw new RefundFailedException("HitPay reported the refund as \"{$status}\" (refund {$id}). Check the HitPay dashboard; once it has succeeded, record it manually with that reference.");
        }

        return (string) $refund['id'];
    }

    /**
     * Save the refund, update the order's refunded total and status, and restock the chosen pairs.
     *
     * @param  list<int>  $restockItemIds
     */
    private function record(ReturnRequest $return, string $amount, RefundMethod $method, string $reference, array $restockItemIds): void
    {
        DB::transaction(function () use ($return, $amount, $method, $reference, $restockItemIds) {
            $current = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            $order = Order::query()->lockForUpdate()->findOrFail($current->order_id);

            if ($current->status !== ReturnStatus::Approved) {
                throw new RefundFailedException('This return was refunded meanwhile.');
            }

            $current->forceFill([
                'status' => ReturnStatus::Refunded,
                'refund_amount' => $amount,
                'refund_method' => $method,
                'refund_reference' => $reference,
                'refunded_at' => now(),
            ])->save();

            foreach ($current->items()->with('orderItem')->get() as $item) {
                if (in_array($item->id, $restockItemIds, true)) {
                    Product::returnToStock($item->orderItem->product_id, $item->quantity);
                    $item->forceFill(['restocked' => true])->save();
                }
            }

            $refundedSen = PlaceOrder::toSen($order->refunded_amount) + PlaceOrder::toSen($amount);
            $order->refunded_amount = PlaceOrder::fromSen($refundedSen);

            if ($refundedSen >= PlaceOrder::toSen($order->total)) {
                $order->status = OrderStatus::Refunded;
            }

            $order->save();
            $return->setRawAttributes($current->getAttributes(), true);
        });
    }
}
