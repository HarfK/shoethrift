<x-layouts.admin title="Dashboard">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Dashboard</h1>
            <p class="mt-1 text-sm text-stone-600">Welcome back, {{ auth()->user()->name }}. Revenue counts paid orders only (COD once the cash is collected), net of refunds.</p>
        </div>
        <a href="{{ route('admin.reports.index') }}" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Sales reports</a>
    </div>

    {{-- Revenue cards --}}
    <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($cards as $card)
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm" data-revenue-card="{{ $card['label'] }}">
                <dt class="text-sm text-stone-600">{{ $card['label'] }}</dt>
                <dd class="mt-2 text-3xl font-semibold tabular-nums">RM {{ number_format((float) $card['summary']['net'], 2) }}</dd>
                <dd class="mt-1 flex flex-wrap items-center gap-2 text-sm text-stone-600">
                    <span>{{ $card['summary']['orders'] }} paid {{ str('order')->plural($card['summary']['orders']) }}</span>
                    @if ($card['change'] !== null)
                        <span @class(['font-medium', 'text-emerald-700' => $card['change'] >= 0, 'text-red-700' => $card['change'] < 0])>
                            {{ $card['change'] >= 0 ? '+' : '' }}{{ $card['change'] }}% {{ $card['compare'] }}
                        </span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm xl:col-span-2">
            <h2 class="text-base font-semibold">Sales, last {{ \App\Http\Controllers\Admin\DashboardController::CHART_DAYS }} days</h2>
            <p class="text-sm text-stone-600">RM {{ number_format(collect($chart)->sum(fn ($row) => (float) $row['net']), 2) }} net from {{ collect($chart)->sum('orders') }} paid orders</p>
            <x-admin.bar-chart :data="$chart" class="mt-4" />
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold">Order status</h2>
            <ul class="mt-3 divide-y divide-stone-100 text-sm">
                @foreach ($statusCounts as $row)
                    <li>
                        <a href="{{ $row['url'] }}" class="flex items-center justify-between py-2 hover:text-emerald-700">
                            @if ($row['status'])
                                <x-order-status-badge :status="$row['status']" />
                            @else
                                <span class="text-stone-600">{{ $row['label'] }}</span>
                            @endif
                            <span class="font-semibold tabular-nums">{{ $row['count'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-stone-100 pt-4 text-sm">
                @foreach ($catalogue as $label => $value)
                    <div><dt class="text-stone-500">{{ $label }}</dt><dd class="text-lg font-semibold tabular-nums">{{ number_format($value) }}</dd></div>
                @endforeach
            </dl>
        </section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold">Top selling products <span class="font-normal text-stone-500">(30 days)</span></h2>
            <ol class="mt-3 space-y-2 text-sm">
                @forelse ($topProducts as $row)
                    <li class="flex items-baseline justify-between gap-3">
                        <a href="{{ route('admin.products.edit', $row['product_id']) }}" class="min-w-0 truncate hover:underline">{{ $row['name'] }} <span class="text-stone-500">{{ $row['size'] }}</span></a>
                        <span class="shrink-0 tabular-nums text-stone-600">{{ $row['units'] }} × · RM {{ number_format((float) $row['sales'], 2) }}</span>
                    </li>
                @empty
                    <li class="text-stone-500">No sales in the last 30 days.</li>
                @endforelse
            </ol>
            @if ($topBrands->isNotEmpty())
                <h3 class="mt-5 text-sm font-semibold">Top brands</h3>
                <p class="mt-1 text-sm text-stone-600">{{ $topBrands->map(fn ($row) => $row['name'].' ('.$row['units'].')')->join(' · ') }}</p>
                <h3 class="mt-3 text-sm font-semibold">Top categories</h3>
                <p class="mt-1 text-sm text-stone-600">{{ $topCategories->map(fn ($row) => $row['name'].' ('.$row['units'].')')->join(' · ') }}</p>
            @endif
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold">Low stock</h2>
            <p class="text-xs text-stone-500">Products that started with more pairs and have {{ config('shop.low_stock_threshold') }} or fewer left.</p>
            <ul class="mt-3 space-y-2 text-sm" data-low-stock>
                @forelse ($lowStock as $product)
                    <li class="flex items-baseline justify-between gap-3">
                        <a href="{{ route('admin.products.edit', $product) }}" class="min-w-0 truncate hover:underline">{{ $product->name }} <span class="text-stone-500">{{ $product->size_label }}</span></a>
                        <span class="shrink-0 rounded bg-amber-100 px-1.5 text-xs font-semibold text-amber-800">{{ $product->stock }} left</span>
                    </li>
                @empty
                    <li class="text-stone-500">Nothing running low.</li>
                @endforelse
            </ul>
            @if ($outOfStock->isNotEmpty())
                <h3 class="mt-5 text-sm font-semibold text-red-800">Listed as available but out of stock</h3>
                <ul class="mt-2 space-y-1 text-sm" data-out-of-stock>
                    @foreach ($outOfStock as $product)
                        <li><a href="{{ route('admin.products.edit', $product) }}" class="hover:underline">{{ $product->name }} <span class="text-stone-500">{{ $product->size_label }}</span></a></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="rounded-xl border border-stone-200 bg-white shadow-sm lg:col-span-2 xl:col-span-1">
            <div class="flex items-center justify-between px-5 pt-5">
                <h2 class="text-base font-semibold">Recent orders</h2>
                <a href="{{ route('admin.orders.index') }}" class="text-sm text-emerald-700 hover:underline">All orders</a>
            </div>
            <ul class="mt-3 divide-y divide-stone-100 text-sm" data-recent-orders>
                @forelse ($recentOrders as $order)
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5">
                        <div class="min-w-0">
                            <a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-emerald-700 hover:underline">{{ $order->order_number }}</a>
                            <div class="truncate text-xs text-stone-500">{{ $order->user->name }} · {{ $order->created_at->shopDateTime() }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="tabular-nums">RM {{ number_format((float) $order->total, 2) }}</div>
                            <x-order-status-badge :order="$order" />
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-stone-500">No orders yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.admin>
