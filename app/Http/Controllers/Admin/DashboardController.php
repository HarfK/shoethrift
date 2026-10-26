<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Checkout\PlaceOrder;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Admin overview. All money figures come from SalesReport (see its docblock for what counts).
 */
class DashboardController extends Controller
{
    public const CHART_DAYS = 30;

    public function __invoke(): View
    {
        $now = ReportPeriod::now();
        $last30 = new SalesReport(ReportPeriod::custom($now->subDays(self::CHART_DAYS - 1), $now));

        return view('admin.dashboard', [
            'cards' => $this->revenueCards($now),
            'chart' => $last30->overTime('day'),
            'statusCounts' => $this->statusCounts(),
            'topProducts' => $last30->byProduct()->take(5),
            'topBrands' => $last30->byBrand()->take(5),
            'topCategories' => $last30->byCategory()->take(5),
            'lowStock' => $this->lowStock(),
            'outOfStock' => Product::where('status', ProductStatus::Available)->where('stock', 0)->orderBy('name')->get(),
            'recentOrders' => Order::with('user')->latest()->latest('id')->limit(8)->get(),
            'catalogue' => [
                'Available products' => Product::available()->count(),
                'Customers' => User::where('role', UserRole::Customer)->count(),
            ],
        ]);
    }

    /**
     * Today / this week / this month so far, each compared with the same span one unit earlier
     * (e.g. Monday-to-now this week vs Monday-to-the-same-time last week).
     *
     * @return list<array{label: string, compare: string, summary: array<string, mixed>, change: ?int}>
     */
    private function revenueCards(CarbonImmutable $now): array
    {
        $spans = [
            ['Today', 'vs yesterday', $now->startOfDay(), fn (CarbonImmutable $t) => $t->subDay()],
            ['This week', 'vs last week', $now->startOfWeek(CarbonImmutable::MONDAY), fn (CarbonImmutable $t) => $t->subWeek()],
            ['This month', 'vs last month', $now->startOfMonth(), fn (CarbonImmutable $t) => $t->subMonthNoOverflow()],
        ];

        return array_map(function (array $span) use ($now) {
            [$label, $compare, $start, $back] = $span;
            $current = (new SalesReport(ReportPeriod::between($start, $now, $label)))->summary();
            $previous = (new SalesReport(ReportPeriod::between($back($start), $back($now), $compare)))->summary();

            $previousSen = PlaceOrder::toSen($previous['net']);
            $change = $previousSen === 0 ? null : (int) round((PlaceOrder::toSen($current['net']) - $previousSen) / $previousSen * 100);

            return ['label' => $label, 'compare' => $compare, 'summary' => $current, 'change' => $change];
        }, $spans);
    }

    /**
     * @return list<array{label: string, count: int, status: ?OrderStatus, url: string}>
     */
    private function statusCounts(): array
    {
        $counts = Order::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $rows = array_map(fn (OrderStatus $status) => [
            'label' => $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
            'status' => $status,
            'url' => route('admin.orders.index', ['status' => $status->value]),
        ], OrderStatus::cases());

        // Subsets shown separately because they need attention or explain a total.
        $rows[] = ['label' => 'Partially refunded', 'count' => Order::where('refunded_amount', '>', 0)->where('status', '!=', OrderStatus::Refunded)->count(), 'status' => null, 'url' => route('admin.returns.index', ['status' => 'refunded'])];
        $rows[] = ['label' => 'Failed delivery (COD)', 'count' => Order::whereNotNull('delivery_failed_at')->count(), 'status' => null, 'url' => route('admin.orders.index', ['status' => 'cancelled', 'payment' => 'cod'])];

        return $rows;
    }

    /**
     * Products that started with more pairs than the threshold and are now down to it or below
     * (stock + pairs sold on live orders > threshold). Single one-of-a-kind pairs never show here.
     *
     * @return Collection<int, Product>
     */
    private function lowStock(): Collection
    {
        $threshold = (int) config('shop.low_stock_threshold');

        return Product::query()
            ->where('status', ProductStatus::Available)
            ->whereBetween('stock', [1, $threshold])
            ->withSum(['orderItems as sold_units' => fn ($query) => $query->whereHas('order', fn ($query) => $query
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Failed]))], 'quantity')
            ->orderBy('stock')
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product) => $product->stock + (int) $product->sold_units > $threshold)
            ->values();
    }
}
