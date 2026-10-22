<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Payments\SyncHitPayPayment;
use Illuminate\Console\Command;

/**
 * Marks online orders Failed when their payment window has passed, returning their stock.
 *
 * Each order is first synced with HitPay (SyncHitPayPayment), so a lost or delayed webhook
 * never fails a paid order. If HitPay can't be reached, the order is left for the next run
 * rather than failed blind.
 *
 * Scheduled every minute in routes/console.php.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Mark online orders not paid within the payment window as failed and restore their stock';

    public function handle(SyncHitPayPayment $sync): int
    {
        $failed = $paid = $skipped = 0;

        $orders = Order::query()
            ->where('payment_method', PaymentMethod::Online)
            ->where('status', OrderStatus::Pending)
            ->whereNull('paid_at')
            ->where('payment_expires_at', '<=', now())
            ->get();

        foreach ($orders as $order) {
            $result = $sync->refresh($order);

            match ($result) {
                'paid', 'already_paid' => $paid++,
                'failed' => $failed++,
                'unreachable' => $skipped++,
                // Still pending at HitPay (or the payment never started): the window is over.
                default => $order->markPaymentFailed() ? $failed++ : $skipped++,
            };
        }

        $this->info("Failed {$failed}, confirmed paid {$paid}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
