<?php

namespace App\Services\Recommendations;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The recommendation blocks:
 *
 * - "You may also like" (product page): final = 0.6 x similarity + 0.4 x personal for a logged-in
 *   customer with data (saved preferences or enough recent interactions); everyone else gets
 *   similarity only. Similarity is SimilarProducts' score scaled to 0-100; when the customer's size
 *   is known, its size rule uses THEIR size instead of the viewed pair's. At least MIN_SCORE, top LIMIT.
 *   Ties: smaller price difference to the viewed pair, then newest, then id.
 * - "For you" (home page): personal score only, above 0, top LIMIT. Ties: newest, then id.
 *
 * For a logged-in customer whose size is known, both blocks only show pairs within ±0.5 UK of it
 * (fewer than LIMIT rather than wrong sizes); guests on the product page are not size-gated.
 * Each card shows at most MAX_REASONS labels: personal ones first (size, brand, category, budget),
 * then similarity ones, without an "as this pair" brand/category label that repeats a personal one.
 *
 * Both leave out unavailable and sold products, pairs in the customer's cart and pairs reserved by
 * another shopper's cart, and show at most one pair per name + colour + size. The product page also
 * leaves out the pair being viewed.
 */
class Recommender
{
    public const LIMIT = 4;

    public const MIN_SCORE = 40;

    public const SIMILARITY_SHARE = 0.6;

    public const PERSONAL_SHARE = 0.4;

    public const MAX_REASONS = 3;

    public function __construct(
        private readonly SimilarProducts $similar,
        private readonly PersonalScore $personal,
    ) {}

    /**
     * Each product gets recommendation_score (final), similarity_score, personal_score (null when
     * not personalised) and recommendation_reasons.
     *
     * @return Collection<int, Product>
     */
    public function forProduct(Product $product, ?User $user): Collection
    {
        $profile = $user ? $this->personal->profileFor($user) : null;
        $personalised = $profile?->hasData ?? false;
        $size = $personalised ? $profile->size : null;
        $viewedPrice = (float) $product->price;

        $scored = $this->similar->scored($product, $this->excludedIds($user), $size)
            ->filter(fn (Product $candidate) => ! $personalised || $profile->fitsSize((float) $candidate->size))
            ->map(function (Product $candidate) use ($profile, $personalised) {
                $similarity = round($candidate->recommendation_score * 100 / SimilarProducts::maxScore(), 2);
                $personal = $personalised ? $this->personal->score($profile, $candidate) : null;
                $final = $personal
                    ? round(self::SIMILARITY_SHARE * $similarity + self::PERSONAL_SHARE * $personal['score'], 2)
                    : $similarity;

                return [
                    'id' => $candidate->id,
                    'key' => self::pairKey($candidate),
                    'final' => $final,
                    'similarity' => $similarity,
                    'personal' => $personal,
                    'price' => (float) $candidate->price,
                    'created' => $candidate->created_at?->getTimestamp() ?? 0,
                ];
            })
            ->filter(fn (array $row) => $row['final'] >= self::MIN_SCORE)
            ->all();

        usort($scored, fn ($a, $b) => [$b['final'], round(abs($a['price'] - $viewedPrice), 2), $b['created'], $b['id']]
            <=> [$a['final'], round(abs($b['price'] - $viewedPrice), 2), $a['created'], $a['id']]);

        $top = self::topDistinct($scored);

        return $this->load($top, $user)->each(function (Product $recommended, int $i) use ($top, $product, $profile, $size) {
            $row = $top[$i];
            $recommended->setAttribute('recommendation_score', $row['final']);
            $recommended->setAttribute('similarity_score', $row['similarity']);
            $recommended->setAttribute('personal_score', $row['personal']['score'] ?? null);
            $recommended->setAttribute('recommendation_reasons', self::topReasons(
                $row['personal'] ? $this->personal->reasons($profile, $recommended, $row['personal']['parts']) : [],
                $this->similar->reasons($product, $recommended, $size),
            ));
        });
    }

    /**
     * Whether "For you" can be personalised at all (saved preferences or enough recent history).
     */
    public function hasPersonalData(User $user): bool
    {
        return $this->personal->profileFor($user)->hasData;
    }

    /**
     * The home page "For you" block: personal score only. Empty when the customer has no data.
     *
     * @return Collection<int, Product>
     */
    public function forYou(User $user): Collection
    {
        $profile = $this->personal->profileFor($user);
        if (! $profile->hasData) {
            return collect();
        }

        $scored = Product::available()
            ->whereNotIn('products.id', $this->excludedIds($user))
            ->when($profile->size !== null, fn ($query) => $query->whereBetween('products.size', [
                $profile->size - PersonalScore::SIZE_GATE, $profile->size + PersonalScore::SIZE_GATE,
            ]))
            ->get(['products.id', 'products.name', 'products.color', 'products.size', 'products.brand', 'products.category_id', 'products.price', 'products.created_at'])
            ->map(fn (Product $candidate) => [
                'id' => $candidate->id,
                'key' => self::pairKey($candidate),
                'personal' => $this->personal->score($profile, $candidate),
                'created' => $candidate->created_at?->getTimestamp() ?? 0,
            ])
            ->filter(fn (array $row) => $row['personal']['score'] > 0)
            ->all();

        usort($scored, fn ($a, $b) => [$b['personal']['score'], $b['created'], $b['id']]
            <=> [$a['personal']['score'], $a['created'], $a['id']]);

        $top = self::topDistinct($scored);

        return $this->load($top, $user)->each(function (Product $recommended, int $i) use ($top, $profile) {
            $recommended->setAttribute('personal_score', $top[$i]['personal']['score']);
            $recommended->setAttribute('recommendation_reasons', self::topReasons($this->personal->reasons($profile, $recommended, $top[$i]['personal']['parts'])));
        });
    }

    /**
     * Products in the customer's cart right now (active holds only).
     *
     * @return list<int>
     */
    public static function cartProductIds(User $user): array
    {
        return CartItem::query()
            ->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
            ->where('reserved_until', '>', now())
            ->pluck('product_id')
            ->all();
    }

    /**
     * Available pairs whose every unit is held in other shoppers' carts (for a guest: anyone's cart).
     *
     * @return list<int>
     */
    public static function reservedByOthersIds(?User $user): array
    {
        return Product::available()
            ->select(['products.id', 'products.status', 'products.stock'])
            ->withHeldByOthers($user?->id)
            ->get()
            ->filter(fn (Product $product) => $product->isReservedByOthers())
            ->modelKeys();
    }

    /**
     * @return list<int>
     */
    private function excludedIds(?User $user): array
    {
        return [...($user ? self::cartProductIds($user) : []), ...self::reservedByOthersIds($user)];
    }

    /**
     * The labels a card shows: personal first (already in the order size, brand, category, budget),
     * then similarity; "Same brand/category as this pair" is dropped when "Brand you like" /
     * "Category you browse" already says it; duplicates removed; at most MAX_REASONS.
     *
     * @param  list<string>  $personal
     * @param  list<string>  $similar
     * @return list<string>
     */
    public static function topReasons(array $personal, array $similar = []): array
    {
        $says = fn (string $prefix) => collect($personal)->contains(fn (string $label) => str_starts_with($label, $prefix));
        $similar = array_filter($similar, fn (string $label) => ! (
            ($says(PersonalScore::BRAND_LABEL) && str_starts_with($label, SimilarProducts::SAME_BRAND_LABEL))
            || ($says(PersonalScore::CATEGORY_LABEL) && str_starts_with($label, SimilarProducts::SAME_CATEGORY_LABEL))
        ));

        return array_slice(array_values(array_unique([...$personal, ...$similar])), 0, self::MAX_REASONS);
    }

    /**
     * Same model, colour and size count as the same pair for the picks.
     */
    private static function pairKey(Product $product): string
    {
        return mb_strtolower(trim($product->name)).'|'.mb_strtolower(trim((string) $product->color)).'|'.Product::formatSize($product->size);
    }

    /**
     * The first LIMIT rows, skipping a row whose pair key was already picked.
     *
     * @param  list<array{key: string}>  $rows  sorted best first
     * @return list<array<string, mixed>>
     */
    private static function topDistinct(array $rows): array
    {
        $picked = [];
        foreach ($rows as $row) {
            if (! isset($picked[$row['key']])) {
                $picked[$row['key']] = $row;
            }
            if (count($picked) === self::LIMIT) {
                break;
            }
        }

        return array_values($picked);
    }

    /**
     * Full models for the chosen rows, in the same order.
     *
     * @param  list<array{id: int}>  $rows
     * @return Collection<int, Product>
     */
    private function load(array $rows, ?User $user): Collection
    {
        $ids = array_column($rows, 'id');
        if ($ids === []) {
            return collect();
        }

        $products = Product::query()
            ->whereKey($ids)
            ->with(['primaryImage', 'category'])
            ->withRatingSummary()
            ->withHeldByOthers($user?->id)
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id) => $products[$id])->values();
    }
}
