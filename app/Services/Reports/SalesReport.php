<?php

namespace App\Services\Reports;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Checkout\PlaceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sales figures for a period, used by the admin dashboard, the reports page and its exports,
 * so every screen agrees.
 *
 * - Revenue orders: Order::revenue() (paid and not cancelled/failed; COD once the cash is collected).
 * - Dated by paid_at (when the money came in), grouped by Malaysian day / month.
 * - Net revenue = order totals (incl. shipping) − refunds recorded on those orders.
 * - Grouping happens in PHP so it works the same on MySQL and SQLite with Malaysia-time days.
 */
class SalesReport
{
    /** @var Collection<int, Order>|null */
    private ?Collection $orders = null;

    public function __construct(public readonly ReportPeriod $period) {}

    /**
     * @return Collection<int, Order>
     */
    public function orders(): Collection
    {
        return $this->orders ??= Order::query()
            ->revenue()
            ->whereBetween('paid_at', [$this->period->start->utc(), $this->period->end->utc()])
            ->with('items.product.category')
            ->orderBy('paid_at')
            ->get();
    }

    /**
     * Money amounts are strings with 2 decimals (computed in sen, like checkout).
     *
     * @return array{orders: int, gross: string, items: string, shipping: string, refunds: string, net: string, average: string}
     */
    public function summary(): array
    {
        $orders = $this->orders();
        $gross = $orders->sum(fn (Order $order) => PlaceOrder::toSen($order->total));
        $shipping = $orders->sum(fn (Order $order) => PlaceOrder::toSen($order->shipping_fee));
        $refunds = $orders->sum(fn (Order $order) => PlaceOrder::toSen($order->refunded_amount));
        $net = $gross - $refunds;

        return [
            'orders' => $orders->count(),
            'gross' => PlaceOrder::fromSen($gross),
            'items' => PlaceOrder::fromSen($gross - $shipping),
            'shipping' => PlaceOrder::fromSen($shipping),
            'refunds' => PlaceOrder::fromSen($refunds),
            'net' => PlaceOrder::fromSen($net),
            'average' => PlaceOrder::fromSen($orders->isEmpty() ? 0 : intdiv($net, $orders->count())),
        ];
    }

    /**
     * Net revenue and order count for every day (or month) in the period, including empty ones.
     *
     * @return array<string, array{label: string, orders: int, net: string}> keyed Y-m-d or Y-m
     */
    public function overTime(?string $grouping = null): array
    {
        $grouping ??= $this->period->grouping();
        $keyFormat = $grouping === 'month' ? 'Y-m' : 'Y-m-d';
        $labelFormat = $grouping === 'month' ? 'M Y' : 'j M';

        $buckets = [];
        for ($cursor = $this->period->start; $cursor->lessThanOrEqualTo($this->period->end); $cursor = $grouping === 'month' ? $cursor->addMonthNoOverflow()->startOfMonth() : $cursor->addDay()) {
            $buckets[$cursor->format($keyFormat)] = ['label' => $cursor->format($labelFormat), 'orders' => 0, 'net' => 0];
        }

        foreach ($this->orders() as $order) {
            $key = $this->local($order->paid_at)->format($keyFormat);
            if (isset($buckets[$key])) {
                $buckets[$key]['orders']++;
                $buckets[$key]['net'] += PlaceOrder::toSen($order->total) - PlaceOrder::toSen($order->refunded_amount);
            }
        }

        return array_map(fn (array $bucket) => [...$bucket, 'net' => PlaceOrder::fromSen($bucket['net'])], $buckets);
    }

    /**
     * Pairs sold and item sales per product, best sellers first (pairs, then sales).
     *
     * @return Collection<int, array{name: string, size: string, brand: string, product_id: int, units: int, sales: string}>
     */
    public function byProduct(): Collection
    {
        return $this->groupItems(fn (OrderItem $item) => $item->product_id, fn (OrderItem $item) => [
            'product_id' => $item->product_id,
            'name' => $item->product->name,
            'size' => $item->product->size_label,
            'brand' => $item->product->brand,
        ]);
    }

    /**
     * @return Collection<int, array{name: string, units: int, sales: string}>
     */
    public function byCategory(): Collection
    {
        return $this->groupItems(fn (OrderItem $item) => $item->product->category_id, fn (OrderItem $item) => [
            'name' => $item->product->category?->name ?? 'Uncategorised',
        ]);
    }

    /**
     * @return Collection<int, array{name: string, units: int, sales: string}>
     */
    public function byBrand(): Collection
    {
        return $this->groupItems(fn (OrderItem $item) => mb_strtolower($item->product->brand), fn (OrderItem $item) => [
            'name' => $item->product->brand,
        ]);
    }

    /**
     * @return Collection<int, array{name: string, orders: int, gross: string, net: string}>
     */
    public function byPaymentMethod(): Collection
    {
        return collect(PaymentMethod::cases())->map(function (PaymentMethod $method) {
            $orders = $this->orders()->where('payment_method', $method);
            $gross = $orders->sum(fn (Order $order) => PlaceOrder::toSen($order->total));
            $refunds = $orders->sum(fn (Order $order) => PlaceOrder::toSen($order->refunded_amount));

            return [
                'name' => $method->label(),
                'orders' => $orders->count(),
                'gross' => PlaceOrder::fromSen($gross),
                'net' => PlaceOrder::fromSen($gross - $refunds),
            ];
        });
    }

    /**
     * @param  callable(OrderItem): (int|string|null)  $key
     * @param  callable(OrderItem): array<string, mixed>  $describe
     * @return Collection<int, array<string, mixed>>
     */
    private function groupItems(callable $key, callable $describe): Collection
    {
        return $this->orders()
            ->flatMap(fn (Order $order) => $order->items)
            ->groupBy($key)
            ->map(fn (Collection $items) => [
                ...$describe($items->first()),
                'units' => (int) $items->sum('quantity'),
                'sen' => $items->sum(fn (OrderItem $item) => PlaceOrder::toSen($item->price) * $item->quantity),
            ])
            ->sortBy([['units', 'desc'], ['sen', 'desc']])
            ->values()
            ->map(function (array $row) {
                $row['sales'] = PlaceOrder::fromSen($row['sen']);
                unset($row['sen']);

                return $row;
            });
    }

    private function local(\DateTimeInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(ReportPeriod::timezone());
    }
}
