<?php

namespace App\Services\Recommendations;

use App\Models\Product;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Personal score (0-100) of a product for one customer. Fixed rules, no machine learning:
 *
 *   size     40  the product is the customer's size (20 when half a size away)
 *   brand    20  a saved preferred brand gets all 20; otherwise 20 x brand weight / highest brand weight
 *   category 20  20 x category weight / highest category weight
 *   budget   20  the price is inside the saved budget (or, with none saved, within 30% of the
 *                weighted average price of the products they interacted with)
 *
 * The customer's size is the saved one, else the size with the most interaction weight.
 * History: only the last 12 months, and only with at least 3 interactions in that time; otherwise
 * nothing is inferred from it. Each interaction weighs view 1, cart 3, purchase 5, halved every 180 days.
 * Only this customer's own preferences and interactions are used.
 */
class PersonalScore
{
    public const WEIGHTS = [
        'size' => 40,
        'brand' => 20,
        'category' => 20,
        'budget' => 20,
    ];

    /** Points for a pair half a size away (inside the ±0.5 size gate). */
    public const NEAR_SIZE_POINTS = 20;

    public const INTERACTION_WEIGHTS = [
        UserInteraction::VIEW => 1,
        UserInteraction::CART => 3,
        UserInteraction::PURCHASE => 5,
    ];

    public const HALF_LIFE_DAYS = 180;

    public const HISTORY_MONTHS = 12;

    /** Fewer interactions than this in the last HISTORY_MONTHS: nothing is inferred from history. */
    public const MIN_INTERACTIONS = 3;

    /** "For you" only shows pairs within this many UK sizes of the customer's size. */
    public const SIZE_GATE = 0.5;

    /** A history brand/category is named in a label only with this many interactions and share of the top weight. */
    public const LABEL_MIN_INTERACTIONS = 2;

    public const LABEL_MIN_SHARE = 0.30;

    public const PRICE_TOLERANCE = 0.30;

    /** Label prefixes; Recommender uses them to drop the matching "as this pair" label. */
    public const BRAND_LABEL = 'Brand you like: ';

    public const CATEGORY_LABEL = 'Category you browse: ';

    public function profileFor(User $user): PersonalProfile
    {
        $preference = $user->preference;
        $now = now();
        $rows = UserInteraction::query()
            ->where('user_interactions.user_id', $user->id)
            ->where('user_interactions.created_at', '>=', $now->copy()->subMonths(self::HISTORY_MONTHS))
            ->join('products', 'products.id', '=', 'user_interactions.product_id')
            ->get(['user_interactions.type', 'user_interactions.created_at', 'products.size', 'products.brand', 'products.category_id', 'products.price']);

        $evidence = $rows->count() >= self::MIN_INTERACTIONS;
        $rows = $evidence ? $rows : collect();
        $rows->each(fn ($row) => $row->setAttribute('weight', $this->weight($row->type, Carbon::parse($row->created_at), $now)));

        $savedBudget = $preference && ($preference->min_price !== null || $preference->max_price !== null)
            ? [$this->floatOrNull($preference->min_price), $this->floatOrNull($preference->max_price)]
            : null;
        $brand = fn ($row) => mb_strtolower($row->brand);
        $category = fn ($row) => (int) $row->category_id;

        return new PersonalProfile(
            size: $preference?->shoe_size !== null ? (float) $preference->shoe_size : $this->mostWeightedSize($rows),
            preferredBrands: array_values(array_map('mb_strtolower', $preference?->preferred_brands ?? [])),
            brandAffinity: $this->affinity($rows, $brand),
            brandCounts: $rows->countBy($brand)->all(),
            categoryAffinity: $this->affinity($rows, $category),
            categoryCounts: $rows->countBy($category)->all(),
            budget: $savedBudget ?? $this->averagePriceRange($rows),
            hasData: ($preference?->hasAny() ?? false) || $evidence,
        );
    }

    /**
     * View 1, cart 3, purchase 5, halved every HALF_LIFE_DAYS.
     */
    public function weight(string $type, Carbon $at, Carbon $now): float
    {
        $days = max(0, $at->diffInSeconds($now)) / 86400;

        return self::INTERACTION_WEIGHTS[$type] * 0.5 ** ($days / self::HALF_LIFE_DAYS);
    }

    /**
     * @return array{score: float, parts: array<string, float>}
     */
    public function score(PersonalProfile $profile, Product $product): array
    {
        $brand = mb_strtolower($product->brand);
        $price = (float) $product->price;
        [$min, $max] = $profile->budget ?? [null, null];
        $sizeGap = $profile->size === null ? null : abs((float) $product->size - $profile->size);

        $parts = [
            'size' => match (true) {
                $sizeGap === null => 0,
                $sizeGap == 0 => self::WEIGHTS['size'],
                $sizeGap <= self::SIZE_GATE => self::NEAR_SIZE_POINTS,
                default => 0,
            },
            'brand' => in_array($brand, $profile->preferredBrands, true)
                ? self::WEIGHTS['brand']
                : self::WEIGHTS['brand'] * ($profile->brandAffinity[$brand] ?? 0),
            'category' => self::WEIGHTS['category'] * ($profile->categoryAffinity[(int) $product->category_id] ?? 0),
            'budget' => $profile->budget !== null && ($min === null || $price >= $min) && ($max === null || $price <= $max)
                ? self::WEIGHTS['budget'] : 0,
        ];
        $parts = array_map(fn ($points) => round((float) $points, 2), $parts);

        return ['score' => round(array_sum($parts), 2), 'parts' => $parts];
    }

    /**
     * Labels for the parts that scored, e.g. ["In your size (UK 11)", "Brand you like: Nike"].
     * Brand and category are only named when the customer clearly likes them (PersonalProfile::likesBrand()).
     * The product needs its category loaded.
     *
     * @param  array<string, float>  $parts
     * @return list<string>
     */
    public function reasons(PersonalProfile $profile, Product $product, array $parts): array
    {
        $size = $profile->size !== null ? 'UK '.Product::formatSize($profile->size) : null;

        return array_values(array_filter([
            ($parts['size'] ?? 0) >= self::WEIGHTS['size'] ? "In your size ({$size})" : null,
            ($parts['size'] ?? 0) > 0 && ($parts['size'] ?? 0) < self::WEIGHTS['size'] ? "Close to your size ({$size})" : null,
            ($parts['brand'] ?? 0) > 0 && $profile->likesBrand($product->brand) ? self::BRAND_LABEL.$product->brand : null,
            ($parts['category'] ?? 0) > 0 && $profile->browsesCategory((int) $product->category_id) && $product->category
                ? self::CATEGORY_LABEL.$product->category->name : null,
            ($parts['budget'] ?? 0) > 0 ? 'Within your budget ('.self::budgetLabel($profile->budget).')' : null,
        ]));
    }

    /**
     * "RM143–266", "from RM100" or "up to RM300" (whole ringgit).
     *
     * @param  array{0: ?float, 1: ?float}  $budget
     */
    public static function budgetLabel(array $budget): string
    {
        [$min, $max] = $budget;
        $rm = fn (float $value) => number_format($value);

        return match (true) {
            $min !== null && $max !== null => 'RM'.$rm($min).'–'.$rm($max),
            $min !== null => 'from RM'.$rm($min),
            default => 'up to RM'.$rm((float) $max),
        };
    }

    /**
     * The size with the most weight; ties go to more interactions, then the smaller size.
     */
    private function mostWeightedSize(Collection $rows): ?float
    {
        // Group sizes as strings: float array keys would be cut to whole numbers.
        $sizes = $rows->groupBy(fn ($row) => number_format((float) $row->size, 1, '.', ''))
            ->map(fn (Collection $group, string $size) => [
                'size' => (float) $size,
                'weight' => round($group->sum('weight'), 6),
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        usort($sizes, fn ($a, $b) => [$b['weight'], $b['count'], $a['size']] <=> [$a['weight'], $a['count'], $b['size']]);

        return $sizes[0]['size'] ?? null;
    }

    /**
     * Total weight per key divided by the highest total.
     *
     * @return array<array-key, float>
     */
    private function affinity(Collection $rows, callable $key): array
    {
        $weights = [];
        foreach ($rows as $row) {
            $k = $key($row);
            $weights[$k] = ($weights[$k] ?? 0) + $row->weight;
        }
        $highest = $weights ? max($weights) : 0;

        return $highest > 0 ? array_map(fn ($weight) => $weight / $highest, $weights) : [];
    }

    /**
     * 30% either side of the weighted average price of the products interacted with, rounded to sen.
     *
     * @return ?array{0: float, 1: float}
     */
    private function averagePriceRange(Collection $rows): ?array
    {
        $total = $rows->sum('weight');
        if ($total <= 0) {
            return null;
        }
        $average = $rows->sum(fn ($row) => (float) $row->price * $row->weight) / $total;

        return [round($average * (1 - self::PRICE_TOLERANCE), 2), round($average * (1 + self::PRICE_TOLERANCE), 2)];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
