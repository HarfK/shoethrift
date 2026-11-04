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
 *   customer with data (saved preferences or interactions); everyone else gets similarity only.
 *   Similarity is SimilarProducts' score scaled to 0-100. At least MIN_SCORE, top LIMIT.
 *   Ties: smaller price difference to the viewed pair, then newest, then id.
 * - "For you" (home page): personal score only, above 0, top LIMIT. Ties: newest, then id.
 *
 * Both leave out unavailable and sold products and pairs in the customer's cart; the product page
 * also leaves out the pair being viewed.
 */
class Recommender
{
    public const LIMIT = 4;

    public const MIN_SCORE = 40;

    public const SIMILARITY_SHARE = 0.6;

    public const PERSONAL_SHARE = 0.4;

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
        $viewedPrice = (float) $product->price;

        $scored = $this->similar->scored($product, $user ? self::cartProductIds($user) : [])
            ->map(function (Product $candidate) use ($profile, $personalised) {
                $similarity = round($candidate->recommendation_score * 100 / SimilarProducts::maxScore(), 2);
                $personal = $personalised ? $this->personal->score($profile, $candidate) : null;
                $final = $personal
                    ? round(self::SIMILARITY_SHARE * $similarity + self::PERSONAL_SHARE * $personal['score'], 2)
                    : $similarity;

                return [
                    'id' => $candidate->id,
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

        $top = array_slice($scored, 0, self::LIMIT);

        return $this->load($top, $user)->each(function (Product $recommended, int $i) use ($top, $product) {
            $row = $top[$i];
            $recommended->setAttribute('recommendation_score', $row['final']);
            $recommended->setAttribute('similarity_score', $row['similarity']);
            $recommended->setAttribute('personal_score', $row['personal']['score'] ?? null);
            $recommended->setAttribute('recommendation_reasons', array_values(array_unique([
                ...$this->similar->reasons($product, $recommended),
                ...($row['personal'] ? $this->personal->reasons($row['personal']['parts']) : []),
            ])));
        });
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
            ->whereNotIn('products.id', self::cartProductIds($user))
            ->get(['products.id', 'products.size', 'products.brand', 'products.category_id', 'products.price', 'products.created_at'])
            ->map(fn (Product $candidate) => [
                'id' => $candidate->id,
                'personal' => $this->personal->score($profile, $candidate),
                'created' => $candidate->created_at?->getTimestamp() ?? 0,
            ])
            ->filter(fn (array $row) => $row['personal']['score'] > 0)
            ->all();

        usort($scored, fn ($a, $b) => [$b['personal']['score'], $b['created'], $b['id']]
            <=> [$a['personal']['score'], $a['created'], $a['id']]);

        $top = array_slice($scored, 0, self::LIMIT);

        return $this->load($top, $user)->each(function (Product $recommended, int $i) use ($top) {
            $recommended->setAttribute('personal_score', $top[$i]['personal']['score']);
            $recommended->setAttribute('recommendation_reasons', $this->personal->reasons($top[$i]['personal']['parts']));
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
