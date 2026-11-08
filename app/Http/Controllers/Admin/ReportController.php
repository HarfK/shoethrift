<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Loyalty\PointsService;
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
            // Loyalty points customers can still use (a liability), and what they are worth.
            'pointsOutstanding' => $outstanding = app(PointsService::class)->outstanding(),
            'pointsOutstandingValue' => app(PointsService::class)->valueOf($outstanding),
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
        $today = ReportPeriod::now()->startOfDay();
        $type = $data['period'] ?? 'month';
        $parse = fn (string $value) => CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz);
        $date = isset($data['date']) ? $parse($data['date']) : $today;

        try {
            $period = match ($type) {
                'day' => ReportPeriod::day($date),
                'week' => ReportPeriod::week($date),
                'month' => ReportPeriod::month(isset($data['month']) ? $parse($data['month'].'-01') : $today),
                'custom' => ReportPeriod::custom($parse($data['from']), $parse($data['to'])),
            };
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['to' => $e->getMessage()]);
        }

        // No future periods. A week or month that has started is fine ("so far").
        if ($period->start->greaterThan($today) || ($type !== 'week' && $type !== 'month' && $period->end->startOfDay()->greaterThan($today))) {
            throw ValidationException::withMessages(match ($type) {
                'day' => ['date' => 'The date can\'t be in the future.'],
                'week' => ['date' => 'The week can\'t be in the future.'],
                'month' => ['month' => 'The month can\'t be in the future.'],
                'custom' => ['to' => 'The end date can\'t be in the future.'],
            });
        }

        return [new SalesReport($period), $this->filtersFor($period)];
    }

    /**
     * The canonical query for a resolved period: what the picker restores, and what Print / PDF / CSV
     * links use, so every output covers exactly the range on screen.
     *
     * @return array<string, string>
     */
    public static function queryFor(ReportPeriod $period): array
    {
        return match ($period->type) {
            'day' => ['period' => 'day', 'date' => $period->start->format('Y-m-d')],
            'week' => ['period' => 'week', 'date' => $period->start->format('Y-m-d')], // always the Monday
            'month' => ['period' => 'month', 'month' => $period->start->format('Y-m')],
            default => ['period' => 'custom', 'from' => $period->start->format('Y-m-d'), 'to' => $period->end->format('Y-m-d')],
        };
    }

    /**
     * Picker state + quick presets for the page.
     *
     * @return array<string, mixed>
     */
    private function filtersFor(ReportPeriod $period): array
    {
        $today = ReportPeriod::now()->startOfDay();
        $presets = [
            'Today' => ReportPeriod::day($today),
            'Yesterday' => ReportPeriod::day($today->subDay()),
            'Last 7 days' => ReportPeriod::custom($today->subDays(6), $today),
            'This month' => ReportPeriod::month($today),
            'Last month' => ReportPeriod::month($today->subMonthNoOverflow()),
        ];
        $query = self::queryFor($period);

        return [
            ...$query,
            'query' => $query,
            'today' => $today->format('Y-m-d'),
            'max_days' => ReportPeriod::MAX_CUSTOM_DAYS,
            'presets' => array_map(fn (ReportPeriod $preset) => [
                'query' => $q = self::queryFor($preset),
                'active' => $q === $query,
            ], $presets),
        ];
    }
}
