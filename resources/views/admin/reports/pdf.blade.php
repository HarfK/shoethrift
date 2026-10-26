{{-- PDF version of the sales report, rendered by dompdf. Plain CSS only: dompdf can't read Tailwind 4. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ShoeThrift sales report: {{ $report->period->label }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1c1917; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 12px; margin: 18px 0 6px; }
        .muted { color: #78716c; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e7e5e4; text-align: left; }
        th { font-size: 9px; text-transform: uppercase; color: #78716c; }
        .num { text-align: right; }
        .summary td { border: none; padding: 2px 6px; }
        .summary .total td { font-weight: bold; border-top: 1px solid #1c1917; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>ShoeThrift sales report</h1>
    <p class="muted">{{ $report->period->label }} · paid orders only (COD once the cash is collected), dated by payment, Malaysia time.<br>
        Generated {{ \App\Services\Reports\ReportPeriod::now()->format('j M Y, g:i a') }}</p>

    <h2>Summary</h2>
    <table class="summary">
        <tr><td>Paid orders</td><td class="num">{{ $summary['orders'] }}</td></tr>
        <tr><td>Item sales</td><td class="num">RM {{ number_format((float) $summary['items'], 2) }}</td></tr>
        <tr><td>Shipping fees</td><td class="num">RM {{ number_format((float) $summary['shipping'], 2) }}</td></tr>
        <tr><td>Gross sales</td><td class="num">RM {{ number_format((float) $summary['gross'], 2) }}</td></tr>
        <tr><td>Refunds</td><td class="num">− RM {{ number_format((float) $summary['refunds'], 2) }}</td></tr>
        <tr class="total"><td>Net revenue</td><td class="num">RM {{ number_format((float) $summary['net'], 2) }}</td></tr>
        <tr><td>Average order (net)</td><td class="num">RM {{ number_format((float) $summary['average'], 2) }}</td></tr>
    </table>

    <h2>By payment method</h2>
    <table>
        <tr><th>Method</th><th class="num">Orders</th><th class="num">Gross</th><th class="num">Net</th></tr>
        @foreach ($byPaymentMethod as $row)
            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['orders'] }}</td><td class="num">RM {{ number_format((float) $row['gross'], 2) }}</td><td class="num">RM {{ number_format((float) $row['net'], 2) }}</td></tr>
        @endforeach
    </table>

    <h2>By category</h2>
    <table>
        <tr><th>Category</th><th class="num">Pairs</th><th class="num">Sales</th></tr>
        @forelse ($byCategory as $row)
            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['units'] }}</td><td class="num">RM {{ number_format((float) $row['sales'], 2) }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">No sales in this period.</td></tr>
        @endforelse
    </table>

    <h2>By product</h2>
    <table>
        <tr><th>Product</th><th>Size</th><th>Brand</th><th class="num">Pairs</th><th class="num">Sales</th></tr>
        @forelse ($byProduct as $row)
            <tr><td>{{ $row['name'] }}</td><td>{{ $row['size'] }}</td><td>{{ $row['brand'] }}</td><td class="num">{{ $row['units'] }}</td><td class="num">RM {{ number_format((float) $row['sales'], 2) }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">No sales in this period.</td></tr>
        @endforelse
    </table>

    <h2>Sales by {{ $report->period->grouping() }}</h2>
    <table>
        <tr><th>{{ ucfirst($report->period->grouping()) }}</th><th class="num">Orders</th><th class="num">Net revenue</th></tr>
        @foreach ($overTime as $row)
            <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['orders'] }}</td><td class="num">RM {{ number_format((float) $row['net'], 2) }}</td></tr>
        @endforeach
    </table>
</body>
</html>
