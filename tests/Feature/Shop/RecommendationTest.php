<?php

namespace Tests\Feature\Shop;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Recommendations\SimilarProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rule-based "You may also like" (SimilarProducts). The viewed pair is a Like-new Nike
 * sneaker, UK 8, RM 300. Each candidate starts with nothing in common with it (UK 12, Boots,
 * Vans, RM 1000, Fair = two condition steps away) and changes only what a test is about.
 */
class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Product $viewed;

    private Category $sneakers;

    private Category $boots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sneakers = Category::factory()->create(['name' => 'Sneakers', 'slug' => 'sneakers']);
        $this->boots = Category::factory()->create(['name' => 'Boots', 'slug' => 'boots']);
        $this->viewed = Product::factory()->create([
            'name' => 'Nike Dunk Low', 'slug' => 'nike-dunk-low', 'brand' => 'Nike', 'size' => 8,
            'category_id' => $this->sneakers->id, 'price' => 300, 'condition' => ProductCondition::LikeNew,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function candidate(array $attributes = []): Product
    {
        return Product::factory()->create([
            'brand' => 'Vans', 'size' => 12, 'category_id' => $this->boots->id, 'price' => 1000,
            'condition' => ProductCondition::Fair, 'stock' => 1, 'status' => ProductStatus::Available,
            ...$attributes,
        ]);
    }

    /**
     * @return Collection<int, Product>
     */
    private function recommend()
    {
        return app(SimilarProducts::class)->for($this->viewed);
    }

    // --- Each rule ------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, int, list<string>}>
     */
    public static function rules(): array
    {
        return [
            'same UK size (+3)' => [['size' => 8], 3, ['Same size']],
            'same category (+2)' => [['category' => 'sneakers'], 2, ['Same category']],
            'same brand, any case (+2)' => [['brand' => 'NIKE'], 2, ['Same brand']],
            'price exactly 30% higher (+1)' => [['price' => 390], 1, ['Similar price']],
            'price exactly 30% lower (+1)' => [['price' => 210], 1, ['Similar price']],
            'condition one step away (+1)' => [['condition' => ProductCondition::Good], 1, ['Similar condition']],
            'same condition (+1)' => [['condition' => ProductCondition::LikeNew], 1, ['Similar condition']],
            'everything (+9)' => [['size' => 8, 'category' => 'sneakers', 'brand' => 'Nike', 'price' => 280, 'condition' => ProductCondition::Good], 9,
                ['Same size', 'Same category', 'Same brand', 'Similar price', 'Similar condition']],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $reasons
     */
    #[DataProvider('rules')]
    public function test_each_rule_scores_its_weight(array $attributes, int $score, array $reasons): void
    {
        if (isset($attributes['category'])) {
            $attributes['category_id'] = $attributes['category'] === 'sneakers' ? $this->sneakers->id : $this->boots->id;
            unset($attributes['category']);
        }
        $this->candidate($attributes);

        $result = $this->recommend()->sole();
        $this->assertSame($score, (int) $result->recommendation_score);
        $this->assertSame($reasons, $result->recommendation_reasons);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function nonMatches(): array
    {
        return [
            'price 31% higher' => [['price' => 393]],
            'price 31% lower' => [['price' => 207]],
            'condition two steps away' => [['condition' => ProductCondition::Fair]],
            'half a size off' => [['size' => 8.5]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('nonMatches')]
    public function test_near_misses_score_nothing_and_are_left_out(array $attributes): void
    {
        $this->candidate($attributes);

        $this->assertCount(0, $this->recommend());
    }

    // --- Selection and order ----------------------------------------------------

    public function test_viewing_a_uk_8_pair_puts_other_uk_8_pairs_first(): void
    {
        // Proposal test plan #13: "selecting size 7 shoes triggers other size 7 options" (here UK 8).
        $sameBrand = $this->candidate(['name' => 'Nike Same Brand', 'brand' => 'Nike']);
        $sameSize = $this->candidate(['name' => 'Other UK 8', 'size' => 8]);

        $this->assertSame([$sameSize->id, $sameBrand->id], $this->recommend()->pluck('id')->all());
    }

    public function test_order_is_score_then_closest_price_then_newest(): void
    {
        $far = $this->candidate(['name' => 'Far price', 'size' => 8, 'price' => 900]);
        $close = $this->candidate(['name' => 'Close price', 'size' => 8, 'price' => 1000, 'created_at' => now()->subDay()]);
        $closeNewer = $this->candidate(['name' => 'Close price, newer', 'size' => 8, 'price' => 1000]);
        $best = $this->candidate(['name' => 'Best', 'size' => 8, 'brand' => 'Nike']);

        // Best (5) first; then the 3-pointers by closeness to RM 300: 900 before 1000; the newer 1000 first.
        $this->assertSame([$best->id, $far->id, $closeNewer->id, $close->id], $this->recommend()->pluck('id')->all());
        $this->assertSame($this->recommend()->pluck('id')->all(), $this->recommend()->pluck('id')->all(), 'Deterministic.');
    }

    public function test_only_available_products_other_than_this_one(): void
    {
        $this->candidate(['size' => 8, 'status' => ProductStatus::Sold, 'stock' => 0]);
        $this->candidate(['size' => 8, 'status' => ProductStatus::Hidden]);
        $this->candidate(['size' => 8, 'stock' => 0]);
        $ok = $this->candidate(['size' => 8]);

        $this->assertSame([$ok->id], $this->recommend()->pluck('id')->all());
        $this->assertNotContains($this->viewed->id, $this->recommend()->pluck('id'));
    }

    public function test_at_most_eight(): void
    {
        foreach (range(1, 10) as $i) {
            $this->candidate(['size' => 8]);
        }

        $this->assertCount(SimilarProducts::LIMIT, $this->recommend());
    }

    // --- Product page ---------------------------------------------------------

    public function test_product_page_shows_recommendations_with_reasons(): void
    {
        $this->candidate(['name' => 'Adidas Samba OG', 'size' => 8, 'brand' => 'Adidas', 'category_id' => $this->sneakers->id]);
        $this->candidate(['name' => 'Totally Unrelated Boot']);

        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee(['You may also like', 'Picked by size, category, brand, price and condition.'])
            ->assertSeeInOrder(['You may also like', 'Adidas Samba OG', 'Same size · Same category', 'Reviews'])
            ->assertDontSee('Totally Unrelated Boot');
    }

    public function test_sold_pairs_still_get_recommendations(): void
    {
        $this->viewed->update(['status' => ProductStatus::Sold, 'stock' => 0]);
        $this->candidate(['name' => 'Another UK 8', 'size' => 8]);

        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee(['This pair is sold. You may also like', 'Another UK 8']);
    }

    public function test_section_is_hidden_when_nothing_matches(): void
    {
        $this->candidate();

        $this->get(route('shop.show', 'nike-dunk-low'))->assertOk()->assertDontSee('You may also like');
    }
}
