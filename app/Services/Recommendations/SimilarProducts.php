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
 *
 * The product page uses Recommender, which scales this score to 0-100 (score / maxScore() * 100)
 * and may mix in PersonalScore; for a customer with a known size it passes THEIR size for the size
 * rule ($size). for() is still used for the cart's "similar pairs" links.
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

    /** Labels for the price and condition rules; size, category and brand labels name the value. */
    public const REASONS = [
        'price' => 'Similar price',
        'condition' => 'Similar condition',
    ];

    public const SAME_CATEGORY_LABEL = 'Same category as this pair';

    public const SAME_BRAND_LABEL = 'Same brand as this pair: ';

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
     * The most points a product can get (all rules match): 9.
     */
    public static function maxScore(): int
    {
        return array_sum(self::WEIGHTS);
    }

    /**
     * Every available product except this one (and $excludeIds), with `recommendation_score`
     * (0 to maxScore()). Only the columns needed for scoring are loaded; no limit.
     *
     * @param  list<int>  $excludeIds
     * @param  ?float  $size  the size for the size rule (the customer's); null = the viewed pair's size
     * @return Collection<int, Product>
     */
    public function scored(Product $product, array $excludeIds = [], ?float $size = null): Collection
    {
        [$scoreSql, $bindings] = $this->scoreExpression($product, $size);

        return Product::available()
            ->whereKeyNot($product->id)
            ->whereNotIn('products.id', $excludeIds)
            ->select(['products.id', 'products.name', 'products.color', 'products.size', 'products.brand', 'products.category_id', 'products.price', 'products.created_at'])
            ->selectRaw("({$scoreSql}) as recommendation_score", $bindings)
            ->get();
    }

    /**
     * Labels for the rules that matched, in weight order, e.g. "Same size as this pair (UK 12)",
     * "Same brand as this pair: Adidas". With $size (the customer's size) the size label is
     * "In your size (UK 11)". The candidate needs its category loaded for the category label.
     *
     * @return list<string>
     */
    public function reasons(Product $product, Product $candidate, ?float $size = null): array
    {
        $ruleSize = $size ?? (float) $product->size;
        $category = $candidate->category?->name;

        return array_values(array_filter([
            (float) $candidate->size === $ruleSize
                ? ($size === null ? 'Same size as this pair ('.$product->size_label.')' : 'In your size (UK '.Product::formatSize($size).')')
                : null,
            $candidate->category_id === $product->category_id
                ? ($category ? self::SAME_CATEGORY_LABEL.": {$category}" : self::SAME_CATEGORY_LABEL)
                : null,
            mb_strtolower($candidate->brand) === mb_strtolower($product->brand) ? self::SAME_BRAND_LABEL.$product->brand : null,
            $this->withinPriceRange((float) $candidate->price, (float) $product->price) ? self::REASONS['price'] : null,
            in_array($candidate->condition, self::similarConditions($product->condition), true) ? self::REASONS['condition'] : null,
        ]));
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
    private function scoreExpression(Product $product, ?float $size = null): array
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

        return [$sql, [$size ?? (float) $product->size, $product->category_id, mb_strtolower($product->brand), $min, $max, ...$conditions]];
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
