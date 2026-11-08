<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Ml\MlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin "Insights" pages, fed by the ML API (App\Services\Ml\MlService). Every page renders even when
 * the API is offline: it then shows the reason instead of the data.
 */
class InsightsController extends Controller
{
    public const RISKS = ['HIGH', 'MEDIUM', 'LOW', 'OVERSTOCK'];

    /** Major campaign events (ml-service forecasting.adjust.MAJOR_EVENTS): labelled in red in the chart and table. */
    public const MAJOR_EVENTS = ['11.11', '12.12', 'black_friday', 'pre_raya', 'raya_closure'];

    /** UK size range of each restock size band (as in ml-service/restock/data.py). */
    public const SIZE_BANDS = ['S' => [3.0, 5.5], 'M' => [6.0, 8.5], 'L' => [9.0, 12.0]];

    public const BAND_NAMES = ['S' => 'Small', 'M' => 'Medium', 'L' => 'Large'];

    /** Restock page views: the group table, or a shopping list per model for sourcing. */
    public const VIEWS = ['groups', 'list'];

    /** Markdown suggested for overstocked groups (the shop's usual first markdown). */
    public const OVERSTOCK_MARKDOWN_PERCENT = 15;

    public function forecast(Request $request, MlService $ml): View
    {
        $days = $request->integer('days') === 30 ? 30 : 7;
        $result = $ml->forecast(30);
        $data = ['days' => $days, 'result' => $result];

        if ($result->ok()) {
            $actual = $result->get('last_30_days_actual', []);
            $complete = array_values(array_filter($actual, fn (array $day) => ! ($day['partial_day'] ?? false)));
            $last7 = array_slice($complete, -7);
            $data += [
                'forecast' => array_slice($result->get('days', []), 0, $days),
                'actual' => $actual,
                'next7' => $result->get('next_7_days'),
                'last7' => ['total' => array_sum(array_column($last7, 'revenue')), 'from' => $last7[0]['date'] ?? null, 'to' => end($last7)['date'] ?? null],
                'trainedUntil' => $result->get('model_trained_until'),
                'today' => $result->get('today'),
            ];
        }

        return view('admin.insights.forecast', $data);
    }

    public function restock(Request $request, MlService $ml): View
    {
        $risk = $this->riskFilter($request);
        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'groups';
        $result = $ml->restock();
        $data = ['risk' => $risk, 'view' => $view, 'result' => $result];

        if ($result->ok()) {
            $groups = collect($result->get('groups', []))->map(fn (array $g) => $this->present($g));
            $shown = $risk ? $groups->where('risk', $risk)->values() : $groups;
            $data += [
                'summary' => $result->get('summary'),
                'groups' => $shown,
                'shoppingList' => self::shoppingList($shown),
                'overstock' => $groups->where('risk', 'OVERSTOCK')
                    ->sortByDesc(fn (array $g) => $g['days_of_cover'] ?? INF)
                    ->map(fn (array $g) => $g + ['products_url' => $this->productsUrl($g)])
                    ->values(),
                'brands' => collect($result->get('brands', [])),
                'asOf' => $result->get('as_of'),
                'dataUntil' => $result->get('data_until'),
            ];
        }

        return view('admin.insights.restock', $data);
    }

    /**
     * The shopping list as CSV (one row per size), for the same risk filter as the page.
     */
    public function restockCsv(Request $request, MlService $ml): StreamedResponse|RedirectResponse
    {
        $risk = $this->riskFilter($request);
        $result = $ml->restock();
        if (! $result->ok()) {
            return redirect()->route('admin.insights.restock', array_filter(['view' => 'list', 'risk' => $risk]))
                ->with('error', 'The restock report is not available right now: '.$result->error);
        }

        $groups = collect($result->get('groups', []))->map(fn (array $g) => $this->present($g));
        $list = self::shoppingList($risk ? $groups->where('risk', $risk)->values() : $groups);
        $name = 'shoethrift-shopping-list-'.($result->get('as_of') ?: now()->format('Y-m-d')).($risk ? '-'.strtolower($risk) : '').'.csv';

        return response()->streamDownload(function () use ($list) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads the dashes and names correctly
            fputcsv($out, ['Model', 'Brand', 'Size band', 'Size (UK)', 'Quantity', 'Risk']);
            foreach ($list as $model) {
                foreach ($model['bands'] as $band) {
                    foreach ($band['sizes'] as $size) {
                        fputcsv($out, [$model['name'], $model['brand'], $band['band_label'], $size['size_label'], $size['qty'], $band['risk']]);
                    }
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Parse the API's "UK 7 x2 (28%), UK 8 x1 (20%)" (most popular first) into chips sorted by size.
     * A chip is a priority when it needs 2+ pairs or is the group's most popular size.
     *
     * @return list<array{size: float, size_label: string, label: string, qty: int, share: ?int, priority: bool}>
     */
    public static function parseSizes(?string $text): array
    {
        preg_match_all('/UK\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+)(?:\s*\((\d+)%\))?/u', (string) $text, $matches, PREG_SET_ORDER);
        $sizes = array_map(fn (array $m) => [
            'size' => (float) $m[1],
            'size_label' => Product::formatSize($m[1]),
            'label' => 'UK '.Product::formatSize($m[1]),
            'qty' => (int) $m[2],
            'share' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
        ], $matches);

        $shares = array_filter(array_column($sizes, 'share'), fn ($share) => $share !== null);
        $topShare = $shares === [] ? null : max($shares);
        foreach ($sizes as &$size) {
            $size['priority'] = $size['qty'] >= 2 || ($topShare !== null && $size['share'] === $topShare);
        }
        unset($size);
        usort($sizes, fn (array $a, array $b) => $a['size'] <=> $b['size']);

        return $sizes;
    }

    /** "Small · UK 3–5.5" */
    public static function bandLabel(string $band): string
    {
        if (! isset(self::SIZE_BANDS[$band])) {
            return $band;
        }
        [$min, $max] = self::SIZE_BANDS[$band];

        return self::BAND_NAMES[$band].' · UK '.Product::formatSize($min).'–'.Product::formatSize($max);
    }

    /**
     * Groups that need pairs, per model: highest risk first, then most pairs, then name; bands S, M, L.
     *
     * @param  Collection<int, array<string, mixed>>  $groups  already presented (see present())
     * @return list<array<string, mixed>>
     */
    public static function shoppingList(Collection $groups): array
    {
        $riskRank = array_flip(self::RISKS);
        $bandRank = array_flip(array_keys(self::SIZE_BANDS));

        return $groups->filter(fn (array $g) => (int) $g['suggested_restock_qty'] > 0)
            ->groupBy('name')
            ->map(function (Collection $bands, string $name) use ($riskRank, $bandRank) {
                $risk = $bands->sortBy(fn (array $g) => $riskRank[$g['risk']] ?? 99)->first()['risk'];

                return [
                    'name' => $name,
                    'brand' => $bands->first()['brand'],
                    'category' => $bands->first()['category'],
                    'risk' => $risk,
                    'risk_rank' => $riskRank[$risk] ?? 99,
                    'total' => (int) $bands->sum('suggested_restock_qty'),
                    'bands' => $bands->sortBy(fn (array $g) => $bandRank[$g['size_band']] ?? 99)->map(fn (array $g) => [
                        'band' => $g['size_band'],
                        'band_label' => $g['band_label'],
                        'risk' => $g['risk'],
                        'qty' => (int) $g['suggested_restock_qty'],
                        'sizes' => $g['sizes'],
                    ])->values()->all(),
                ];
            })
            ->sort(fn (array $a, array $b) => [$a['risk_rank'], -$a['total'], $a['name']] <=> [$b['risk_rank'], -$b['total'], $b['name']])
            ->values()
            ->all();
    }

    public function status(MlService $ml): View
    {
        return view('admin.insights.status', ['result' => $ml->health(), 'configured' => $ml->configured()]);
    }

    public function retrain(MlService $ml): RedirectResponse
    {
        $result = $ml->retrain();

        if ($result->ok()) {
            return back()->with('success', 'Retraining started in the background (about 1 minute). Refresh this page to see the new training dates.');
        }

        return back()->with('error', $result->status === 409
            ? 'A retrain is already running. Refresh this page in a minute.'
            : 'Retrain could not be started: '.$result->error);
    }

    /**
     * Presentation fields only (the ML output itself is unchanged): size chips and the band label.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function present(array $group): array
    {
        return $group + [
            'sizes' => self::parseSizes($group['sizes_to_look_for'] ?? ''),
            'band_label' => self::bandLabel((string) ($group['size_band'] ?? '')),
        ];
    }

    private function riskFilter(Request $request): ?string
    {
        $risk = strtoupper($request->string('risk')->toString());

        return in_array($risk, self::RISKS, true) ? $risk : null;
    }

    /**
     * Products list filtered to this group's model and size band (available pairs).
     *
     * @param  array<string, mixed>  $group
     */
    private function productsUrl(array $group): string
    {
        [$min, $max] = self::SIZE_BANDS[$group['size_band']] ?? [null, null];

        return route('admin.products.index', array_filter([
            'search' => $group['name'], 'size_min' => $min, 'size_max' => $max, 'status' => 'available',
        ], fn ($v) => $v !== null));
    }

    /** Display a Y-m-d date from the API as a shop date (e.g. "11 Nov 2026"). */
    public static function day(?string $date): string
    {
        return $date ? Carbon::parse($date, 'UTC')->shopDate() : '';
    }
}
