<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Loyalty\PointsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Give loyalty points for orders completed before the points programme existed, as if it had always run:
 * each order earns on its completion date (COD: when the cash was collected; online: when delivered) and
 * expires expiry_months later, partial refunds take points back on the refund date, and points that are past
 * their expiry are expired on their expiry date. Events are processed in date order, so the ledger reads like
 * real history. Safe to run again: orders that already have points are skipped.
 */
class LoyaltyBackfill extends Command
{
    protected $signature = 'loyalty:backfill';

    protected $description = 'Give loyalty points for existing completed orders (dated, with expiry)';

    public function handle(PointsService $points): int
    {
        $orders = Order::query()->where('status', OrderStatus::Completed)->with('items')->get();
        $events = [];
        foreach ($orders as $order) {
            $completedAt = $order->payment_method === PaymentMethod::CashOnDelivery ? $order->paid_at : $order->delivered_at;
            $completedAt = Carbon::instance($completedAt ?? $order->updated_at ?? $order->created_at);
            $events[] = [$completedAt, 0, 'earn', $order];
            if ((float) $order->refunded_amount > 0) {
                $events[] = [Carbon::instance(max($completedAt, $order->updated_at)), 1, 'refund', $order];
            }
        }
        usort($events, fn ($a, $b) => [$a[0]->getTimestamp(), $a[1], $a[3]->id] <=> [$b[0]->getTimestamp(), $b[1], $b[3]->id]);

        $earned = 0;
        $bar = $this->output->createProgressBar(count($events));
        foreach ($events as [$at, , $kind, $order]) {
            if ($kind === 'earn') {
                $earned += $points->earnFor($order, $at)?->points ?? 0;
            } else {
                $points->reverseFor($order, $at);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $expired = $points->expireDue();
        $this->info(sprintf('%d completed orders · %s points earned · %s points expired now · %s points outstanding (RM %s).',
            $orders->count(), number_format($earned), number_format($expired), number_format($points->outstanding()), $points->valueOf($points->outstanding())));

        return self::SUCCESS;
    }
}
