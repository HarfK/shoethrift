<?php

namespace App\Services\Recommendations;

use App\Enums\ProductCondition;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "You may also like": rule-based recommendations (proposal objective 2, module 5). No machine
 * learning: each available product (except the one being viewed) gets points from fixed IF-THEN
 * rules, scored in one SQL query so it runs the same on MySQL and SQLite.
 *
 *   IF same UK size                         THEN +3   (fit matters most for second-hand shoes)
 *   IF same category                        THEN +2
 *   IF same brand (case-insensitive)        THEN +2
 *   IF price within ±30% of this product    THEN +1
 *   IF condition the same or one step away  THEN +1   (Like new ↔ Good ↔ Fair)
 *
 * Products scoring 0 are left out. Order: score (high first), then closest price, newest, id,
 * so the same page always gets the same list.
 */
class SimilarProducts
{
    public const LIMIT = 8;

    public const PRICE_TOLERANCE = 0.30;

    public const WEIGHTS = [
        'size' => 3,
        'category' => 2,
        'brand' => 2,
        'price' => 1,
        'condition' => 1,
    ];

    public const REASONS = [
        'size' => 'Same size',
        'category' => 'Same category',
        'brand' => 'Same brand',
        'price' => 'Similar price',
        'condition' => 'Similar condition',
    ];

    /**
     * Up to LIMIT recommendations, each with `recommendation_score` and `recommendation_reasons`.
     *
     * @return Collection<int, Product>
     */
    public function for(Product $product, int $limit = self::LIMIT): Collection
    {
        [$scoreSql, $bindings] = $this->scoreExpression($product);
        $priceSql = DB::getQueryGrammar()->wrap('price');

        return Product::available()
            ->whereKeyNot($product->id)
            ->select('products.*')
            ->selectRaw("({$scoreSql}) as recommendation_score", $bindings)
            ->whereRaw("({$scoreSql}) > 0", $bindings)
            ->with(['primaryImage', 'category'])
            ->withRatingSummary()
            ->orderByDesc('recommendation_score')
            ->orderByRaw("abs({$priceSql} - ?)", [(float) $product->price])
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->each(fn (Product $candidate) => $candidate->setAttribute('recommendation_reasons', $this->reasons($product, $candidate)));
    }

    /**
     * Which rules matched, in weight order (for the "Same size · Same brand" label).
     *
     * @return list<string>
     */
    public function reasons(Product $product, Product $candidate): array
    {
        $matched = [
            'size' => (float) $candidate->size === (float) $product->size,
            'category' => $candidate->category_id === $product->category_id,
            'brand' => mb_strtolower($candidate->brand) === mb_strtolower($product->brand),
            'price' => $this->withinPriceRange((float) $candidate->price, (float) $product->price),
            'condition' => in_array($candidate->condition, self::similarConditions($product->condition), true),
        ];

        return array_values(array_map(fn (string $rule) => self::REASONS[$rule], array_keys(array_filter($matched))));
    }

    /**
     * The same condition and its neighbours in Like new → Good → Fair.
     *
     * @return list<ProductCondition>
     */
    public static function similarConditions(ProductCondition $condition): array
    {
        $order = ProductCondition::cases();
        $index = array_search($condition, $order, true);

        return array_values(array_filter($order, fn (ProductCondition $c) => abs(array_search($c, $order, true) - $index) <= 1));
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function scoreExpression(Product $product): array
    {
        $grammar = DB::getQueryGrammar();
        [$min, $max] = $this->priceRange((float) $product->price);
        $conditions = array_map(fn (ProductCondition $c) => $c->value, self::similarConditions($product->condition));
        $w = self::WEIGHTS;

        $sql = implode(' + ', [
            "(case when {$grammar->wrap('size')} = ? then {$w['size']} else 0 end)",
            "(case when {$grammar->wrap('category_id')} = ? then {$w['category']} else 0 end)",
            "(case when lower({$grammar->wrap('brand')}) = ? then {$w['brand']} else 0 end)",
            "(case when {$grammar->wrap('price')} between ? and ? then {$w['price']} else 0 end)",
            "(case when {$grammar->wrap('condition')} in (".implode(', ', array_fill(0, count($conditions), '?')).") then {$w['condition']} else 0 end)",
        ]);

        return [$sql, [(float) $product->size, $product->category_id, mb_strtolower($product->brand), $min, $max, ...$conditions]];
    }

    /**
     * Inclusive ±30% range, rounded to sen.
     *
     * @return array{0: float, 1: float}
     */
    private function priceRange(float $price): array
    {
        return [round($price * (1 - self::PRICE_TOLERANCE), 2), round($price * (1 + self::PRICE_TOLERANCE), 2)];
    }

    private function withinPriceRange(float $candidate, float $price): bool
    {
        [$min, $max] = $this->priceRange($price);

        return $candidate >= $min && $candidate <= $max;
    }
}
