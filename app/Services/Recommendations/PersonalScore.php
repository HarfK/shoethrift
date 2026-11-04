<?php

namespace App\Services\Recommendations;

use App\Models\Product;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Collection;

/**
 * Personal score (0-100) of a product for one customer. Fixed rules, no machine learning:
 *
 *   size     40  the product is the saved shoe size (or, with none saved, the size they
 *                interacted with most often)
 *   brand    20  a saved preferred brand gets all 20; otherwise 20 x brand weight / highest brand weight
 *   category 20  20 x category weight / highest category weight
 *   budget   20  the price is inside the saved budget (or, with none saved, within 30% of the
 *                average price of the products they interacted with)
 *
 * Interaction weights: view 1, cart 3, purchase 5. A part with no data scores 0.
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

    public const INTERACTION_WEIGHTS = [
        UserInteraction::VIEW => 1,
        UserInteraction::CART => 3,
        UserInteraction::PURCHASE => 5,
    ];

    public const PRICE_TOLERANCE = 0.30;

    public const REASONS = [
        'size' => 'Your size',
        'brand' => 'Brand you like',
        'budget' => 'In your budget',
    ];

    public function profileFor(User $user): PersonalProfile
    {
        $preference = $user->preference;
        $rows = UserInteraction::query()
            ->where('user_interactions.user_id', $user->id)
            ->join('products', 'products.id', '=', 'user_interactions.product_id')
            ->get(['user_interactions.type', 'products.size', 'products.brand', 'products.category_id', 'products.price']);

        $savedBudget = $preference && ($preference->min_price !== null || $preference->max_price !== null)
            ? [$this->floatOrNull($preference->min_price), $this->floatOrNull($preference->max_price)]
            : null;

        return new PersonalProfile(
            size: $preference?->shoe_size !== null ? (float) $preference->shoe_size : $this->mostFrequentSize($rows),
            preferredBrands: array_values(array_map('mb_strtolower', $preference?->preferred_brands ?? [])),
            brandAffinity: $this->affinity($rows, fn ($row) => mb_strtolower($row->brand)),
            categoryAffinity: $this->affinity($rows, fn ($row) => (int) $row->category_id),
            budget: $savedBudget ?? $this->averagePriceRange($rows),
            hasData: ($preference?->hasAny() ?? false) || $rows->isNotEmpty(),
        );
    }

    /**
     * @return array{score: float, parts: array<string, float>}
     */
    public function score(PersonalProfile $profile, Product $product): array
    {
        $brand = mb_strtolower($product->brand);
        $price = (float) $product->price;
        [$min, $max] = $profile->budget ?? [null, null];

        $parts = [
            'size' => $profile->size !== null && (float) $product->size === $profile->size ? self::WEIGHTS['size'] : 0,
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
     * Labels for the parts that scored, e.g. ["Your size", "In your budget"].
     *
     * @param  array<string, float>  $parts
     * @return list<string>
     */
    public function reasons(array $parts): array
    {
        return array_values(array_filter(array_map(
            fn (string $part) => ($parts[$part] ?? 0) > 0 ? self::REASONS[$part] : null,
            array_keys(self::REASONS),
        )));
    }

    /**
     * The size seen most often; ties go to the higher total weight, then the smaller size.
     */
    private function mostFrequentSize(Collection $rows): ?float
    {
        // Group sizes as strings: float array keys would be cut to whole numbers.
        $sizes = $rows->groupBy(fn ($row) => number_format((float) $row->size, 1, '.', ''))
            ->map(fn (Collection $group, string $size) => [
                'size' => (float) $size,
                'count' => $group->count(),
                'weight' => $group->sum(fn ($row) => self::INTERACTION_WEIGHTS[$row->type]),
            ])
            ->values()
            ->all();

        usort($sizes, fn ($a, $b) => [$b['count'], $b['weight'], $a['size']] <=> [$a['count'], $a['weight'], $b['size']]);

        return $sizes[0]['size'] ?? null;
    }

    /**
     * Total weight per key (view 1, cart 3, purchase 5) divided by the highest total.
     *
     * @return array<array-key, float>
     */
    private function affinity(Collection $rows, callable $key): array
    {
        $weights = [];
        foreach ($rows as $row) {
            $k = $key($row);
            $weights[$k] = ($weights[$k] ?? 0) + self::INTERACTION_WEIGHTS[$row->type];
        }
        $highest = $weights ? max($weights) : 0;

        return $highest > 0 ? array_map(fn ($weight) => $weight / $highest, $weights) : [];
    }

    /**
     * 30% either side of the average price of the products interacted with, rounded to sen.
     *
     * @return ?array{0: float, 1: float}
     */
    private function averagePriceRange(Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }
        $average = $rows->avg(fn ($row) => (float) $row->price);

        return [round($average * (1 - self::PRICE_TOLERANCE), 2), round($average * (1 + self::PRICE_TOLERANCE), 2)];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
