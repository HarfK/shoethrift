<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SalesReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales report for a day, week, month or custom range, as a page (print-friendly), PDF or CSV.
 * The figures come from SalesReport, the same source as the dashboard.
 */
class ReportController extends Controller
{
    public function index(Request $request): View
    {
        [$report, $filters] = $this->report($request);

        return view('admin.reports.index', $this->viewData($report) + ['filters' => $filters]);
    }

    public function pdf(Request $request): Response
    {
        [$report] = $this->report($request);

        return Pdf::loadView('admin.reports.pdf', $this->viewData($report))
            ->setPaper('a4')
            ->download('shoethrift-sales-'.$report->period->slug().'.pdf');
    }

    public function csv(Request $request): StreamedResponse
    {
        [$report] = $this->report($request);
        $summary = $report->summary();

        return response()->streamDownload(function () use ($report, $summary) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads "RM" and names correctly

            fputcsv($out, ['ShoeThrift sales report', $report->period->label]);
            fputcsv($out, ['Generated', ReportPeriod::now()->format('j M Y, g:i a')]);
            fputcsv($out, []);

            fputcsv($out, ['Summary', 'Amount (RM)']);
            fputcsv($out, ['Paid orders', $summary['orders']]);
            foreach (['gross' => 'Gross sales', 'items' => 'Item sales', 'shipping' => 'Shipping fees', 'refunds' => 'Refunds', 'net' => 'Net revenue', 'average' => 'Average order value (net)'] as $key => $label) {
                fputcsv($out, [$label, $summary[$key]]);
            }
            fputcsv($out, []);

            fputcsv($out, [$report->period->grouping() === 'month' ? 'Month' : 'Date', 'Orders', 'Net revenue (RM)']);
            foreach ($report->overTime() as $key => $row) {
                fputcsv($out, [$key, $row['orders'], $row['net']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Product', 'Size', 'Brand', 'Pairs', 'Sales (RM)']);
            foreach ($report->byProduct() as $row) {
                fputcsv($out, [$row['name'], $row['size'], $row['brand'], $row['units'], $row['sales']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Category', 'Pairs', 'Sales (RM)']);
            foreach ($report->byCategory() as $row) {
                fputcsv($out, [$row['name'], $row['units'], $row['sales']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Payment method', 'Orders', 'Gross (RM)', 'Net (RM)']);
            foreach ($report->byPaymentMethod() as $row) {
                fputcsv($out, [$row['name'], $row['orders'], $row['gross'], $row['net']]);
            }

            fclose($out);
        }, 'shoethrift-sales-'.$report->period->slug().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(SalesReport $report): array
    {
        return [
            'report' => $report,
            'summary' => $report->summary(),
            'overTime' => $report->overTime(),
            'byProduct' => $report->byProduct(),
            'byCategory' => $report->byCategory(),
            'byPaymentMethod' => $report->byPaymentMethod(),
        ];
    }

    /**
     * Read the period from the query string: period=day|week|month|custom with date / month /
     * from+to. Defaults to this month.
     *
     * @return array{0: SalesReport, 1: array<string, string>}
     *
     * @throws ValidationException
     */
    private function report(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['day', 'week', 'month', 'custom'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
        ]);

        $tz = ReportPeriod::timezone();
        $today = ReportPeriod::now();
        $type = $data['period'] ?? 'month';
        $date = isset($data['date']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['date'], $tz) : $today;

        try {
            $period = match ($type) {
                'day' => ReportPeriod::day($date),
                'week' => ReportPeriod::week($date),
                'month' => ReportPeriod::month(isset($data['month']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01', $tz) : $today),
                'custom' => ReportPeriod::custom(
                    CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz),
                    CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz),
                ),
            };
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['to' => $e->getMessage()]);
        }

        $filters = [
            'period' => $type,
            'date' => $date->format('Y-m-d'),
            'month' => $period->start->format('Y-m'),
            'from' => $data['from'] ?? $today->startOfMonth()->format('Y-m-d'),
            'to' => $data['to'] ?? $today->format('Y-m-d'),
        ];

        return [new SalesReport($period), $filters];
    }
}
