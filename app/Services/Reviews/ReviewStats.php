<?php

namespace App\Services\Reviews;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Review aggregates for the whole store, a brand or a category, and the "related reviews"
 * shown on product pages that have none of their own (most pairs are one-of-a-kind).
 *
 * - Only public reviews count: not hidden, and on products that aren't hidden.
 * - Each aggregate is one query, cached for CACHE_SECONDS, and forgotten immediately when a
 *   review changes (Review model events → forgetFor()). The database cache has no tags, so the
 *   exact keys are cleared.
 * - Review lists load the reviewer as `user:id,name` only: email, phone and address are never
 *   fetched for public pages.
 */
class ReviewStats
{
    public const CACHE_SECONDS = 600;

    public const RELATED_LIMIT = 3;

    /** A brand needs at least this many reviews; otherwise the category is used. */
    public const MIN_BRAND_REVIEWS = 3;

    /**
     * @return array{average: ?float, count: int, condition: ?float}
     */
    public static function store(): array
    {
        return Cache::remember(self::storeKey(), self::CACHE_SECONDS, fn () => self::aggregate(self::publicReviews()));
    }

    /**
     * @return array{average: ?float, count: int, condition: ?float}
     */
    public static function brand(string $brand, ?int $exceptProductId = null): array
    {
        return Cache::remember(self::brandKey($brand).':'.($exceptProductId ?? 0), self::CACHE_SECONDS,
            fn () => self::aggregate(self::forBrand($brand, $exceptProductId)));
    }

    /**
     * @return array{average: ?float, count: int, condition: ?float}
     */
    public static function category(int $categoryId, ?int $exceptProductId = null): array
    {
        return Cache::remember(self::categoryKey($categoryId).':'.($exceptProductId ?? 0), self::CACHE_SECONDS,
            fn () => self::aggregate(self::forCategory($categoryId, $exceptProductId)));
    }

    /**
     * What to show on a product page without reviews of its own: the brand's reviews when the
     * brand (other pairs of it) has at least MIN_BRAND_REVIEWS, otherwise the category's, otherwise
     * nothing. Never includes the product itself.
     *
     * @return array{scope: 'brand'|'category', label: string, stats: array{average: ?float, count: int, condition: ?float}, reviews: Collection<int, Review>}|null
     */
    public static function relatedFor(Product $product): ?array
    {
        $brandStats = self::brand($product->brand, $product->id);

        [$scope, $label, $stats, $query] = $brandStats['count'] >= self::MIN_BRAND_REVIEWS
            ? ['brand', $product->brand, $brandStats, self::forBrand($product->brand, $product->id)]
            : ['category', $product->category?->name ?? 'similar shoes', self::category($product->category_id, $product->id), self::forCategory($product->category_id, $product->id)];

        if ($stats['count'] === 0) {
            return null;
        }

        return [
            'scope' => $scope,
            'label' => $label,
            'stats' => $stats,
            'reviews' => $query
                ->with(['user:id,name', 'product:id,slug,name,brand,size,category_id'])
                ->latest()
                ->latest('id')
                ->limit(self::RELATED_LIMIT)
                ->get(),
        ];
    }

    /**
     * Clear the cached aggregates a review contributes to (store, its brand, its category).
     * Brand/category keys also include the product being viewed (it's excluded from its own
     * block), so instead of forgetting each one, their version number is bumped.
     */
    public static function forgetFor(Review $review): void
    {
        Cache::forget(self::storeKey());

        $product = $review->relationLoaded('product') ? $review->product : Product::find($review->product_id);
        if ($product) {
            Cache::forever(self::versionKey('brand', self::normaliseBrand($product->brand)), (string) microtime(true));
            Cache::forever(self::versionKey('category', (string) $product->category_id), (string) microtime(true));
        }
    }

    /**
     * @param  Builder<Review>  $reviews
     * @return array{average: ?float, count: int, condition: ?float}
     */
    private static function aggregate(Builder $reviews): array
    {
        $row = $reviews->toBase()->reorder()
            ->selectRaw('count(*) as total, avg(reviews.rating) as average, avg(reviews.condition_rating) as condition_average')
            ->first();

        $count = (int) ($row->total ?? 0);

        return [
            'average' => $count > 0 ? round((float) $row->average, 1) : null,
            'count' => $count,
            'condition' => $count > 0 && $row->condition_average !== null ? round((float) $row->condition_average, 1) : null,
        ];
    }

    /**
     * Visible reviews on products that aren't hidden.
     *
     * @return Builder<Review>
     */
    public static function publicReviews(): Builder
    {
        return Review::query()->visible()
            ->whereHas('product', fn (Builder $query) => $query->where('status', '!=', ProductStatus::Hidden));
    }

    /**
     * @return Builder<Review>
     */
    private static function forBrand(string $brand, ?int $exceptProductId): Builder
    {
        return self::publicReviews()
            ->whereHas('product', fn (Builder $query) => $query->whereRaw('lower(brand) = ?', [self::normaliseBrand($brand)]))
            ->when($exceptProductId, fn (Builder $query) => $query->where('product_id', '!=', $exceptProductId));
    }

    /**
     * @return Builder<Review>
     */
    private static function forCategory(?int $categoryId, ?int $exceptProductId): Builder
    {
        return self::publicReviews()
            ->whereHas('product', fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($exceptProductId, fn (Builder $query) => $query->where('product_id', '!=', $exceptProductId));
    }

    private static function normaliseBrand(string $brand): string
    {
        return mb_strtolower(trim($brand));
    }

    private static function storeKey(): string
    {
        return 'reviews:store';
    }

    private static function brandKey(string $brand): string
    {
        $brand = self::normaliseBrand($brand);

        return 'reviews:brand:'.md5($brand).':v'.Cache::get(self::versionKey('brand', $brand), '0');
    }

    private static function categoryKey(int $categoryId): string
    {
        return 'reviews:category:'.$categoryId.':v'.Cache::get(self::versionKey('category', (string) $categoryId), '0');
    }

    private static function versionKey(string $scope, string $value): string
    {
        return "reviews:{$scope}-version:".md5($value);
    }
}
