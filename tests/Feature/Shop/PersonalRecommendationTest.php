<?php

namespace Tests\Feature\Shop;

use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\UserInteraction;
use App\Services\Recommendations\PersonalScore;
use App\Services\Recommendations\Recommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Personal recommendations: PersonalScore (0-100), the hybrid "You may also like"
 * (0.6 x similarity + 0.4 x personal), the home page "For you", interaction logging and the
 * shoe preferences form.
 *
 * The viewed pair is a Like-new Nike sneaker, UK 8, RM 300. Candidates start with nothing in
 * common with it (UK 12, Boots, Vans, RM 1000, Fair, a unique name) and change only what a test is about.
 * History needs at least 3 interactions in the last 12 months, so most history tests use 3.
 */
class PersonalRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $viewed;

    private Category $sneakers;

    private Category $boots;

    private int $candidates = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
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
            'name' => 'Candidate '.++$this->candidates, 'color' => 'Black',
            'brand' => 'Vans', 'size' => 12, 'category_id' => $this->boots->id, 'price' => 1000,
            'condition' => ProductCondition::Fair, 'stock' => 1, 'status' => ProductStatus::Available,
            ...$attributes,
        ]);
    }

    private function interact(Product $product, string $type, ?User $user = null, ?Carbon $at = null): void
    {
        $interaction = new UserInteraction(['user_id' => ($user ?? $this->user)->id, 'product_id' => $product->id, 'type' => $type]);
        if ($at) {
            $interaction->created_at = $at;
        }
        $interaction->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function prefer(array $values, ?User $user = null): void
    {
        ($user ?? $this->user)->preference()->create($values);
    }

    /**
     * @return array{score: float, parts: array<string, float>}
     */
    private function personal(Product $product): array
    {
        $service = app(PersonalScore::class);

        return $service->score($service->profileFor($this->user->fresh()), $product);
    }

    /**
     * @return list<string>
     */
    private function personalReasons(Product $product): array
    {
        $service = app(PersonalScore::class);
        $profile = $service->profileFor($this->user->fresh());

        return $service->reasons($profile, $product->load('category'), $service->score($profile, $product)['parts']);
    }

    /**
     * @return list<int>
     */
    private function recommendedIds(?User $user): array
    {
        return app(Recommender::class)->forProduct($this->viewed, $user)->pluck('id')->all();
    }

    private function holdInCart(Product $product, User $user, bool $active = true): void
    {
        Cart::firstOrCreate(['user_id' => $user->id])->items()->create([
            'product_id' => $product->id, 'quantity' => 1, 'reserved_until' => $active ? now()->addMinutes(10) : now()->subMinute(),
        ]);
    }

    // --- Personal score: each part ----------------------------------------------

    public function test_no_data_scores_zero_in_every_part(): void
    {
        $profile = app(PersonalScore::class)->profileFor($this->user);

        $this->assertFalse($profile->hasData);
        $this->assertSame(['size' => 0.0, 'brand' => 0.0, 'category' => 0.0, 'budget' => 0.0], $this->personal($this->candidate())['parts']);
    }

    public function test_size_40_for_the_saved_size_and_20_half_a_size_away(): void
    {
        $this->prefer(['shoe_size' => 8.5]);

        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 8.5]))['parts']['size']);
        $this->assertSame(20.0, $this->personal($this->candidate(['size' => 9]))['parts']['size']);
        $this->assertSame(20.0, $this->personal($this->candidate(['size' => 8]))['parts']['size']);
        $this->assertSame(0.0, $this->personal($this->candidate(['size' => 9.5]))['parts']['size']);
    }

    public function test_without_a_saved_size_the_size_with_the_most_weight_is_used(): void
    {
        // One purchase of UK 10 (5) outweighs two views of UK 9 (2).
        $this->interact($this->candidate(['size' => 9]), 'view');
        $this->interact($this->candidate(['size' => 9]), 'view');
        $this->interact($this->candidate(['size' => 10]), 'purchase');

        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 10]))['parts']['size']);
        $this->assertSame(0.0, $this->personal($this->candidate(['size' => 11]))['parts']['size']);

        // A saved size wins over history.
        $this->prefer(['shoe_size' => 12]);
        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 12]))['parts']['size']);
    }

    public function test_equal_weights_go_to_more_interactions_then_the_smaller_size(): void
    {
        $this->interact($this->candidate(['size' => 9]), 'cart');   // 3 in one
        foreach (range(1, 3) as $i) {
            $this->interact($this->candidate(['size' => 11]), 'view'); // 3 in three
        }
        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 11]))['parts']['size']);

        UserInteraction::query()->delete();
        foreach ([9.5, 9.5, 7.5, 7.5] as $size) {
            $this->interact($this->candidate(['size' => $size]), 'view');
        }
        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 7.5]))['parts']['size'], 'Half sizes are kept apart.');
    }

    public function test_brand_uses_weights_view_1_cart_3_purchase_5_divided_by_the_highest(): void
    {
        $this->interact($this->candidate(['brand' => 'Nike']), 'view');     // Nike 1
        $this->interact($this->candidate(['brand' => 'Nike']), 'cart');     // Nike 1 + 3 = 4
        $this->interact($this->candidate(['brand' => 'Adidas']), 'purchase'); // Adidas 5 (highest)

        $this->assertSame(20.0, $this->personal($this->candidate(['brand' => 'ADIDAS']))['parts']['brand']);
        $this->assertSame(16.0, $this->personal($this->candidate(['brand' => 'nike']))['parts']['brand']); // 20 x 4/5
        $this->assertSame(0.0, $this->personal($this->candidate(['brand' => 'Puma']))['parts']['brand']);
    }

    public function test_a_saved_preferred_brand_gets_the_full_20(): void
    {
        $this->interact($this->candidate(['brand' => 'Nike']), 'purchase');
        $this->interact($this->candidate(['brand' => 'Puma']), 'view');
        $this->interact($this->candidate(['brand' => 'Asics']), 'view');
        $this->prefer(['preferred_brands' => ['Puma']]);

        $this->assertSame(20.0, $this->personal($this->candidate(['brand' => 'puma']))['parts']['brand']);
        $this->assertSame(20.0, $this->personal($this->candidate(['brand' => 'Nike']))['parts']['brand']);
    }

    public function test_category_uses_the_same_weighted_affinity(): void
    {
        $this->interact($this->candidate(['category_id' => $this->sneakers->id]), 'view'); // 1
        $this->interact($this->candidate(['category_id' => $this->boots->id]), 'cart');    // 3
        $this->interact($this->candidate(['category_id' => $this->boots->id]), 'view');    // boots 4 (highest)

        $this->assertSame(20.0, $this->personal($this->candidate(['category_id' => $this->boots->id]))['parts']['category']);
        $this->assertSame(5.0, $this->personal($this->candidate(['category_id' => $this->sneakers->id]))['parts']['category']);
    }

    public function test_budget_20_inside_the_saved_budget(): void
    {
        $this->prefer(['min_price' => 100, 'max_price' => 200]);

        foreach ([[100, 20.0], [200, 20.0], [99.99, 0.0], [200.01, 0.0]] as [$price, $points]) {
            $this->assertSame($points, $this->personal($this->candidate(['price' => $price]))['parts']['budget'], "RM {$price}");
        }
    }

    public function test_a_budget_with_only_a_maximum_is_open_below(): void
    {
        $this->prefer(['max_price' => 150]);

        $this->assertSame(20.0, $this->personal($this->candidate(['price' => 20]))['parts']['budget']);
        $this->assertSame(0.0, $this->personal($this->candidate(['price' => 151]))['parts']['budget']);
    }

    public function test_without_a_saved_budget_the_weighted_average_price_plus_minus_30_percent_is_used(): void
    {
        $this->interact($this->candidate(['price' => 100]), 'view');
        $this->interact($this->candidate(['price' => 200]), 'view');
        $this->interact($this->candidate(['price' => 300]), 'view'); // average RM 200 → RM 140 to 260

        foreach ([[140, 20.0], [260, 20.0], [139.99, 0.0], [260.01, 0.0]] as [$price, $points]) {
            $this->assertSame($points, $this->personal($this->candidate(['price' => $price]))['parts']['budget'], "RM {$price}");
        }

        // A purchase weighs 5 views: (100 + 200 + 5 x 300) / 7 = 257.14 → RM 180 to 334.29.
        UserInteraction::query()->where('type', 'view')->whereIn('product_id', Product::where('price', 300)->pluck('id'))->update(['type' => 'purchase']);
        $this->assertSame(20.0, $this->personal($this->candidate(['price' => 334]))['parts']['budget']);
        $this->assertSame(0.0, $this->personal($this->candidate(['price' => 179]))['parts']['budget']);
    }

    public function test_all_parts_add_up_to_100(): void
    {
        $this->prefer(['shoe_size' => 9, 'preferred_brands' => ['Adidas'], 'min_price' => 100, 'max_price' => 300]);
        foreach (range(1, 3) as $i) {
            $this->interact($this->candidate(['category_id' => $this->sneakers->id]), 'view');
        }

        $result = $this->personal($this->candidate(['size' => 9, 'brand' => 'Adidas', 'category_id' => $this->sneakers->id, 'price' => 200]));
        $this->assertSame(100.0, $result['score']);
    }

    // --- Recency and minimum evidence ----------------------------------------------

    public function test_interactions_halve_in_weight_every_180_days(): void
    {
        $service = app(PersonalScore::class);
        $now = Carbon::parse('2026-11-12 12:00');

        $this->assertSame(5.0, $service->weight('purchase', $now, $now));
        $this->assertSame(2.5, $service->weight('purchase', $now->copy()->subDays(180), $now));
        $this->assertSame(0.75, $service->weight('cart', $now->copy()->subDays(360), $now));

        // Nike viewed today (1) against two Adidas views from 360 days ago (2 x 0.25 = 0.5).
        $this->interact($this->candidate(['brand' => 'Nike']), 'view');
        $this->interact($this->candidate(['brand' => 'Adidas']), 'view', at: now()->subDays(360));
        $this->interact($this->candidate(['brand' => 'Adidas']), 'view', at: now()->subDays(360));

        $this->assertSame(20.0, $this->personal($this->candidate(['brand' => 'Nike']))['parts']['brand']);
        $this->assertSame(10.0, $this->personal($this->candidate(['brand' => 'Adidas']))['parts']['brand']);
    }

    public function test_history_needs_3_interactions_in_the_last_12_months(): void
    {
        $nike = $this->candidate(['brand' => 'Nike', 'size' => 10]);
        $this->interact($nike, 'purchase');
        $this->interact($nike, 'cart');
        foreach (range(1, 5) as $i) {
            $this->interact($nike, 'purchase', at: now()->subMonths(13)); // too old to count
        }

        $profile = app(PersonalScore::class)->profileFor($this->user);
        $this->assertFalse($profile->hasData);
        $this->assertNull($profile->size);
        $this->assertSame(0.0, $this->personal($this->candidate(['brand' => 'Nike', 'size' => 10]))['score']);
        $this->actingAs($this->user)->get(route('home'))->assertSee('Set your shoe size, brands and budget');

        // A third recent interaction is enough.
        $this->interact($nike, 'view');
        $this->assertTrue(app(PersonalScore::class)->profileFor($this->user)->hasData);
        $this->assertSame(10.0, app(PersonalScore::class)->profileFor($this->user)->size);
    }

    public function test_saved_preferences_count_even_without_history_but_nothing_is_inferred(): void
    {
        $this->interact($this->candidate(['brand' => 'Nike']), 'purchase');
        $this->prefer(['max_price' => 500]);

        $profile = app(PersonalScore::class)->profileFor($this->user);
        $this->assertTrue($profile->hasData);
        $this->assertSame([], $profile->brandAffinity);
        $this->assertNull($profile->size);
    }

    // --- Reason labels -------------------------------------------------------------

    public function test_personal_labels_name_the_size_brand_category_and_budget(): void
    {
        $this->prefer(['shoe_size' => 11, 'min_price' => 143.44, 'max_price' => 266.38]);
        foreach (range(1, 3) as $i) {
            $this->interact($this->candidate(['brand' => 'Nike', 'category_id' => $this->sneakers->id]), 'view');
        }

        $this->assertSame(
            ['In your size (UK 11)', 'Brand you like: Nike', 'Category you browse: Sneakers', 'Within your budget (RM143–266)'],
            $this->personalReasons($this->candidate(['size' => 11, 'brand' => 'Nike', 'category_id' => $this->sneakers->id, 'price' => 200])),
        );
        $this->assertSame(['Close to your size (UK 11)'], $this->personalReasons($this->candidate(['size' => 10.5])));
    }

    public function test_budget_labels_with_one_open_end(): void
    {
        $this->assertSame('from RM100', PersonalScore::budgetLabel([100.0, null]));
        $this->assertSame('up to RM1,200', PersonalScore::budgetLabel([null, 1200.0]));
    }

    public function test_history_brands_and_categories_are_named_only_with_2_interactions_and_30_percent(): void
    {
        foreach (range(1, 4) as $i) {
            $this->interact($this->candidate(['brand' => 'Nike', 'category_id' => $this->sneakers->id]), 'view'); // Nike 4, Sneakers 4
        }
        $this->interact($this->candidate(['brand' => 'Puma']), 'cart');  // Puma 3 (75%) but 1 interaction
        $this->interact($this->candidate(['brand' => 'Asics']), 'view'); // Asics 2 (50%), 2 interactions
        $this->interact($this->candidate(['brand' => 'Asics']), 'view');
        $this->interact($this->candidate(['brand' => 'Vans', 'category_id' => $this->boots->id]), 'view');

        $this->assertContains('Brand you like: Nike', $this->personalReasons($this->candidate(['brand' => 'Nike'])));
        $this->assertContains('Brand you like: Asics', $this->personalReasons($this->candidate(['brand' => 'Asics'])));
        $this->assertNotContains('Brand you like: Puma', $this->personalReasons($this->candidate(['brand' => 'Puma'])), 'Only 1 interaction.');
        $this->assertGreaterThan(0, $this->personal($this->candidate(['brand' => 'Puma']))['parts']['brand'], 'It still scores.');
        $this->assertContains('Category you browse: Sneakers', $this->personalReasons($this->candidate(['category_id' => $this->sneakers->id])));
    }

    public function test_a_brand_below_30_percent_is_not_named_but_a_saved_brand_always_is(): void
    {
        foreach (range(1, 4) as $i) {
            $this->interact($this->candidate(['brand' => 'Nike']), 'purchase'); // 20
        }
        $this->interact($this->candidate(['brand' => 'Fila']), 'view'); // 2 of 20 = 10%
        $this->interact($this->candidate(['brand' => 'Fila']), 'view');

        $this->assertNotContains('Brand you like: Fila', $this->personalReasons($this->candidate(['brand' => 'Fila'])));

        $this->prefer(['preferred_brands' => ['Fila']]);
        $this->assertContains('Brand you like: Fila', $this->personalReasons($this->candidate(['brand' => 'Fila'])));
    }

    // --- Only the customer's own data -------------------------------------------

    public function test_only_the_users_own_preferences_and_interactions_are_used(): void
    {
        $other = User::factory()->create();
        $this->prefer(['shoe_size' => 12, 'preferred_brands' => ['Vans']], $other);
        foreach (range(1, 3) as $i) {
            $this->interact($this->candidate(['brand' => 'Vans']), 'purchase', $other);
        }

        $profile = app(PersonalScore::class)->profileFor($this->user);
        $this->assertFalse($profile->hasData);
        $this->assertSame(0.0, $this->personal($this->candidate())['score']);
    }

    // --- Hybrid "You may also like" ----------------------------------------------

    public function test_cold_start_uses_similarity_only_like_a_guest(): void
    {
        $match = $this->candidate(['size' => 8, 'category_id' => $this->sneakers->id]); // 5 of 9 points

        $recommendations = app(Recommender::class)->forProduct($this->viewed, $this->user);

        $this->assertSame([$match->id], $recommendations->pluck('id')->all());
        $this->assertSame(55.56, $recommendations->first()->recommendation_score);
        $this->assertNull($recommendations->first()->personal_score);
        $this->assertSame(['Same size as this pair (UK 8)', 'Same category as this pair: Sneakers'], $recommendations->first()->recommendation_reasons);
        $this->assertSame($this->recommendedIds(null), $this->recommendedIds($this->user));
    }

    public function test_similarity_is_scaled_to_100_with_a_minimum_of_40(): void
    {
        $sizeOnly = $this->candidate(['size' => 8]);                       // 3/9 = 33.33
        $sizeAndPrice = $this->candidate(['size' => 8, 'price' => 300]);   // 4/9 = 44.44

        $recommendations = app(Recommender::class)->forProduct($this->viewed, null);

        $this->assertSame([$sizeAndPrice->id], $recommendations->pluck('id')->all());
        $this->assertSame(44.44, $recommendations->first()->similarity_score);
        $this->assertNotContains($sizeOnly->id, $recommendations->pluck('id'));
    }

    public function test_at_most_four(): void
    {
        foreach (range(1, 6) as $i) {
            $this->candidate(['size' => 8, 'category_id' => $this->sneakers->id]);
        }

        $this->assertCount(Recommender::LIMIT, app(Recommender::class)->forProduct($this->viewed, null));
    }

    public function test_the_size_rule_uses_the_customers_size_and_guests_keep_the_viewed_pairs(): void
    {
        $this->prefer(['shoe_size' => 12]);
        // The user's size + same category and brand: similarity 7/9 = 77.78, personal 40 → 0.6 x 77.78 + 0.4 x 40 = 62.67.
        $forUser = $this->candidate(['name' => 'Nike Boot UK 12', 'brand' => 'Nike', 'category_id' => $this->sneakers->id]);
        // The viewed pair's size + category: for the user only the category counts (22.22) → 13.33, below 40.
        $forGuest = $this->candidate(['name' => 'Vans UK 8', 'size' => 8, 'category_id' => $this->sneakers->id]);

        $recommendations = app(Recommender::class)->forProduct($this->viewed, $this->user);
        $this->assertSame([$forUser->id], $recommendations->pluck('id')->all());
        $this->assertSame([62.67, 77.78, 40.0], [
            $recommendations->first()->recommendation_score,
            $recommendations->first()->similarity_score,
            $recommendations->first()->personal_score,
        ]);
        $this->assertSame(
            ['In your size (UK 12)', 'Same category as this pair: Sneakers', 'Same brand as this pair: Nike'],
            $recommendations->first()->recommendation_reasons,
        );

        // Guests are unaffected by the user's data.
        $guest = app(Recommender::class)->forProduct($this->viewed, null);
        $this->assertSame([$forGuest->id, $forUser->id], $guest->pluck('id')->all());
        $this->assertContains('Same size as this pair (UK 8)', $guest->first()->recommendation_reasons);
    }

    public function test_the_product_page_is_size_gated_for_customers_with_a_known_size_only(): void
    {
        $this->prefer(['shoe_size' => 12]);
        // Matches the viewed UK 8 pair in everything but the customer's size.
        $wrongSize = $this->candidate(['size' => 8, 'brand' => 'Nike', 'category_id' => $this->sneakers->id, 'price' => 300, 'condition' => ProductCondition::LikeNew]);
        $halfSize = $this->candidate(['size' => 11.5, 'brand' => 'Nike', 'category_id' => $this->sneakers->id, 'price' => 300]);

        $this->assertSame([$halfSize->id], $this->recommendedIds($this->user));
        $this->assertContains($wrongSize->id, $this->recommendedIds(null), 'Guests are not gated.');

        // A customer with data but no known size is not gated either.
        $other = User::factory()->create();
        $this->prefer(['preferred_brands' => ['Nike']], $other);
        $this->assertContains($wrongSize->id, $this->recommendedIds($other));
    }

    public function test_cards_show_at_most_3_labels_with_personal_ones_first_and_no_repeats(): void
    {
        $this->prefer(['shoe_size' => 12, 'preferred_brands' => ['Nike'], 'max_price' => 400]);
        foreach (range(1, 3) as $i) {
            $this->interact($this->candidate(['size' => 3, 'category_id' => $this->sneakers->id]), 'view');
        }
        // Personal: size, brand, category, budget; similarity: category, brand, price, condition.
        $this->candidate(['size' => 12, 'brand' => 'Nike', 'category_id' => $this->sneakers->id, 'price' => 300, 'condition' => ProductCondition::LikeNew]);

        $this->assertSame(
            ['In your size (UK 12)', 'Brand you like: Nike', 'Category you browse: Sneakers'],
            app(Recommender::class)->forProduct($this->viewed, $this->user)->first()->recommendation_reasons,
        );
    }

    public function test_a_personal_brand_or_category_label_replaces_the_same_as_this_pair_one(): void
    {
        $this->assertSame(
            ['In your size (UK 12)', 'Brand you like: Nike', 'Similar price'],
            Recommender::topReasons(['In your size (UK 12)', 'Brand you like: Nike'], ['In your size (UK 12)', 'Same brand as this pair: Nike', 'Similar price', 'Similar condition']),
        );
        $this->assertSame(
            ['Category you browse: Boots', 'Same brand as this pair: Vans', 'Similar price'],
            Recommender::topReasons(['Category you browse: Boots'], ['Same category as this pair: Boots', 'Same brand as this pair: Vans', 'Similar price']),
        );
        $this->assertSame(
            ['Same size as this pair (UK 8)', 'Same category as this pair: Sneakers', 'Same brand as this pair: Nike'],
            Recommender::topReasons([], ['Same size as this pair (UK 8)', 'Same category as this pair: Sneakers', 'Same brand as this pair: Nike', 'Similar price']),
            'Guests: the first 3 similarity labels.',
        );
    }

    public function test_order_is_final_score_then_smaller_price_difference_then_newest(): void
    {
        $best = $this->candidate(['size' => 8, 'category_id' => $this->sneakers->id, 'brand' => 'Nike']);
        $far = $this->candidate(['size' => 8, 'category_id' => $this->sneakers->id, 'price' => 1000, 'created_at' => now()->subDay()]);
        $near = $this->candidate(['size' => 8, 'category_id' => $this->sneakers->id, 'price' => 900, 'created_at' => now()->subDay()]);
        $farNewer = $this->candidate(['size' => 8, 'category_id' => $this->sneakers->id, 'price' => 1000]);

        $this->assertSame([$best->id, $near->id, $farNewer->id, $far->id], $this->recommendedIds(null));
        $this->assertSame($this->recommendedIds(null), $this->recommendedIds(null), 'Deterministic.');
    }

    public function test_unavailable_sold_viewed_in_cart_and_reserved_products_are_left_out(): void
    {
        $match = ['size' => 8, 'category_id' => $this->sneakers->id];
        $this->candidate([...$match, 'status' => ProductStatus::Sold, 'stock' => 0]);
        $this->candidate([...$match, 'status' => ProductStatus::Hidden]);
        $this->candidate([...$match, 'stock' => 0]);
        $inCart = $this->candidate($match);
        $expiredLine = $this->candidate($match);
        $ok = $this->candidate($match);

        $this->holdInCart($inCart, $this->user);
        $this->holdInCart($expiredLine, $this->user, active: false);

        $this->assertEqualsCanonicalizing([$ok->id, $expiredLine->id], $this->recommendedIds($this->user));
        $this->assertNotContains($this->viewed->id, $this->recommendedIds($this->user));
        $this->assertNotContains($inCart->id, $this->recommendedIds(null), 'Reserved for someone else.');
        $this->assertNotContains($inCart->id, $this->recommendedIds(User::factory()->create()));
    }

    public function test_a_pair_with_a_free_unit_left_is_still_recommended(): void
    {
        $twoPairs = $this->candidate(['size' => 8, 'category_id' => $this->sneakers->id, 'stock' => 2]);
        $this->holdInCart($twoPairs, User::factory()->create());

        $this->assertContains($twoPairs->id, $this->recommendedIds($this->user));
    }

    public function test_at_most_one_pair_per_name_colour_and_size(): void
    {
        $same = ['name' => 'Adidas Samba OG', 'color' => 'Black/White', 'size' => 8, 'category_id' => $this->sneakers->id];
        $first = $this->candidate([...$same, 'price' => 300]);
        $duplicate = $this->candidate([...$same, 'name' => 'adidas samba og', 'price' => 310]);
        $otherColour = $this->candidate([...$same, 'color' => 'White', 'price' => 320]);
        $otherSize = $this->candidate([...$same, 'size' => 8.5, 'brand' => 'Nike', 'price' => 300]);

        $ids = $this->recommendedIds(null);
        $this->assertContains($first->id, $ids);
        $this->assertNotContains($duplicate->id, $ids);
        $this->assertContains($otherColour->id, $ids);
        $this->assertContains($otherSize->id, $ids);
    }

    public function test_product_page_marks_personal_picks_and_tags_links(): void
    {
        $this->prefer(['shoe_size' => 12]);
        $pick = $this->candidate(['name' => 'Nike Boot UK 12', 'slug' => 'nike-boot-uk-12', 'brand' => 'Nike', 'category_id' => $this->sneakers->id]);

        $this->actingAs($this->user)->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee(['You may also like', 'Also based on your shoe preferences', 'Nike Boot UK 12',
                'In your size (UK 12) · Same category as this pair: Sneakers · Same brand as this pair: Nike'])
            ->assertSee(route('shop.show', ['slug' => $pick->slug, 'src' => 'recommendation']), false);

        auth()->logout();
        $this->get(route('shop.show', 'nike-dunk-low'))->assertDontSee('Also based on your shoe preferences');
    }

    // --- Home "For you" ------------------------------------------------------------

    public function test_for_you_uses_the_personal_score_only(): void
    {
        $this->prefer(['shoe_size' => 9, 'max_price' => 200]);
        $sizeAndBudget = $this->candidate(['name' => 'Size and budget', 'size' => 9, 'price' => 150]); // 60
        $sizeOnly = $this->candidate(['name' => 'Size only', 'size' => 9]);                          // 40
        $nearSize = $this->candidate(['name' => 'Half a size up', 'size' => 9.5]);                   // 20
        $this->candidate(['name' => 'Budget but wrong size', 'price' => 100]);                       // UK 12: gated out
        $inCart = $this->candidate(['name' => 'Already in cart', 'size' => 9, 'price' => 150]);
        $this->holdInCart($inCart, $this->user);

        $forYou = app(Recommender::class)->forYou($this->user);
        $this->assertSame([$sizeAndBudget->id, $sizeOnly->id, $nearSize->id], $forYou->pluck('id')->all());
        $this->assertSame(['In your size (UK 9)', 'Within your budget (up to RM200)'], $forYou->first()->recommendation_reasons);
        $this->assertSame(['Close to your size (UK 9)'], $forYou->last()->recommendation_reasons);

        $this->actingAs($this->user)->get(route('home'))
            ->assertSee('data-for-you', false)
            ->assertSeeInOrder(['For you', 'Size and budget', 'Size only', 'Half a size up', 'Newly added'])
            ->assertSee(route('shop.show', ['slug' => $sizeOnly->slug, 'src' => 'for_you']), false);
    }

    public function test_for_you_never_shows_wrong_sizes_even_if_that_leaves_fewer_than_4(): void
    {
        $this->prefer(['shoe_size' => 11, 'preferred_brands' => ['Nike']]);
        $fits = [$this->candidate(['size' => 11, 'brand' => 'Nike']), $this->candidate(['size' => 10.5]), $this->candidate(['size' => 11.5])];
        foreach ([7, 10, 12, 13] as $size) {
            $this->candidate(['size' => $size, 'brand' => 'Nike']);
        }

        $this->assertEqualsCanonicalizing(collect($fits)->pluck('id')->all(), app(Recommender::class)->forYou($this->user)->pluck('id')->all());
    }

    public function test_for_you_says_when_nothing_is_in_the_customers_size(): void
    {
        $this->prefer(['shoe_size' => 4]);
        $this->candidate(['size' => 12]);

        $this->actingAs($this->user)->get(route('home'))
            ->assertSee('No pairs in your size right now.')
            ->assertDontSee('Set your shoe size, brands and budget');
    }

    public function test_for_you_leaves_out_reserved_pairs_and_duplicates(): void
    {
        $this->prefer(['shoe_size' => 9]);
        $sambas = [$this->candidate(['name' => 'Samba', 'size' => 9])->id, $this->candidate(['name' => 'Samba', 'size' => 9])->id];
        $reserved = $this->candidate(['size' => 9]);
        $this->holdInCart($reserved, User::factory()->create());

        $ids = app(Recommender::class)->forYou($this->user)->pluck('id')->all();
        $this->assertCount(1, $ids);
        $this->assertContains($ids[0], $sambas);
    }

    public function test_for_you_cards_show_at_most_3_labels_and_drop_the_budget_first(): void
    {
        $this->prefer(['shoe_size' => 9, 'preferred_brands' => ['Nike'], 'max_price' => 400]);
        foreach (range(1, 3) as $i) {
            $this->interact($this->candidate(['size' => 3, 'category_id' => $this->sneakers->id]), 'view');
        }
        $this->candidate(['size' => 9, 'brand' => 'Nike', 'category_id' => $this->sneakers->id, 'price' => 300]);

        $this->assertSame(
            ['In your size (UK 9)', 'Brand you like: Nike', 'Category you browse: Sneakers'],
            app(Recommender::class)->forYou($this->user)->first()->recommendation_reasons,
        );
    }

    public function test_for_you_keeps_the_top_4(): void
    {
        $this->prefer(['shoe_size' => 9]);
        foreach (range(1, 6) as $i) {
            $this->candidate(['size' => 9]);
        }

        $this->assertCount(4, app(Recommender::class)->forYou($this->user));
    }

    public function test_for_you_asks_for_preferences_when_there_is_no_data_and_is_hidden_for_guests(): void
    {
        $this->actingAs($this->user)->get(route('home'))
            ->assertSee('data-for-you', false)
            ->assertSee('Set your shoe size, brands and budget');

        auth()->logout();
        $this->get(route('home'))->assertDontSee('data-for-you', false);
    }

    // --- Logging interactions --------------------------------------------------------

    public function test_product_views_are_logged_for_customers_only(): void
    {
        $this->get(route('shop.show', 'nike-dunk-low'))->assertOk();
        $this->assertSame(0, UserInteraction::count(), 'Guests are not logged.');

        $this->actingAs($this->user)->get(route('shop.show', 'nike-dunk-low'));
        $this->get(route('shop.show', 'nike-dunk-low')); // refresh: not logged again
        $this->assertSame(1, UserInteraction::where('type', 'view')->count());

        $this->travel(31)->minutes();
        $this->get(route('shop.show', 'nike-dunk-low'));
        $this->assertSame(2, UserInteraction::where('type', 'view')->count());
    }

    public function test_clicks_from_recommendation_blocks_store_the_source(): void
    {
        $this->actingAs($this->user)->get(route('shop.show', ['slug' => 'nike-dunk-low', 'src' => 'recommendation']));
        $this->get(route('shop.show', ['slug' => 'nike-dunk-low', 'src' => 'for_you']));
        $this->get(route('shop.show', ['slug' => 'nike-dunk-low', 'src' => 'spam']));

        $this->assertSame(['recommendation', 'for_you', null], UserInteraction::orderBy('id')->pluck('source')->all());
    }

    public function test_add_to_cart_is_logged(): void
    {
        $this->actingAs($this->user)->post(route('cart.items.store'), ['product_id' => $this->viewed->id])->assertSessionHas('success');

        $this->assertDatabaseHas('user_interactions', ['user_id' => $this->user->id, 'product_id' => $this->viewed->id, 'type' => 'cart']);
    }

    public function test_cash_on_delivery_orders_are_purchases_when_placed(): void
    {
        $this->actingAs($this->user)->post(route('cart.items.store'), ['product_id' => $this->viewed->id]);
        $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah', 'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => number_format(300 + config('shop.shipping_fee'), 2, '.', ''),
            'payment_method' => 'cod',
        ])->assertRedirect();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, UserInteraction::where('type', 'purchase')->where('product_id', $this->viewed->id)->count());
    }

    public function test_online_orders_are_purchases_only_once_paid(): void
    {
        $order = Order::factory()->for($this->user)->online()->status(OrderStatus::Pending)->create();
        OrderItem::factory()->for($order)->for($this->viewed)->create();
        $this->assertSame(0, UserInteraction::where('type', 'purchase')->count());

        $order->recordGatewayPayment('req-1', 'pay-1');
        $order->fresh()->transitionTo(OrderStatus::Shipped);

        $this->assertSame(1, UserInteraction::where('type', 'purchase')->where('user_id', $this->user->id)->count());
    }

    public function test_nothing_is_logged_when_the_order_is_rolled_back(): void
    {
        try {
            DB::transaction(function () {
                $order = Order::factory()->for($this->user)->cashOnDelivery()->create();
                OrderItem::factory()->for($order)->for($this->viewed)->create();

                throw new RuntimeException('checkout failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, UserInteraction::count());
    }

    // --- Shoe preferences form ---------------------------------------------------------

    public function test_customers_can_save_shoe_preferences(): void
    {
        $this->candidate(['brand' => 'Adidas']);

        $this->actingAs($this->user)->get(route('account.profile'))
            ->assertSee(['Shoe preferences', 'UK shoe size', 'Adidas', 'Budget (RM)']);

        $this->from(route('account.profile'))->patch(route('account.preferences.update'), [
            'shoe_size' => '8.5', 'preferred_brands' => ['Nike', 'Adidas'], 'min_price' => '50', 'max_price' => '250',
        ])->assertRedirect(route('account.profile'))->assertSessionHas('success', 'Your shoe preferences were saved.');

        $preference = $this->user->fresh()->preference;
        $this->assertSame(['8.5', ['Nike', 'Adidas'], '50.00', '250.00'], [$preference->shoe_size, $preference->preferred_brands, $preference->min_price, $preference->max_price]);

        // Clearing everything is allowed.
        $this->patch(route('account.preferences.update'), [])->assertSessionHasNoErrors();
        $this->assertFalse($this->user->fresh()->preference->hasAny());
    }

    public function test_shoe_preferences_are_validated(): void
    {
        $this->actingAs($this->user);

        // [input, field that must have an error]
        $cases = [
            [['shoe_size' => '15.5'], 'shoe_size'],
            [['shoe_size' => '8.3'], 'shoe_size'],
            [['preferred_brands' => ['Not A Brand']], 'preferred_brands.0'],
            [['min_price' => '-1'], 'min_price'],
            [['min_price' => '300', 'max_price' => '200'], 'max_price'],
            [['max_price' => 'abc'], 'max_price'],
        ];

        foreach ($cases as [$input, $field]) {
            $this->patch(route('account.preferences.update'), $input)->assertSessionHasErrorsIn('preferences', $field);
        }
        $this->assertNull($this->user->fresh()->preference);
    }

    public function test_more_than_five_brands_are_rejected(): void
    {
        foreach (['Nike', 'Adidas', 'Puma', 'Vans', 'Asics', 'Converse'] as $brand) {
            $this->candidate(['brand' => $brand]);
        }

        $this->actingAs($this->user)->patch(route('account.preferences.update'), [
            'preferred_brands' => ['Nike', 'Adidas', 'Puma', 'Vans', 'Asics', 'Converse'],
        ])->assertSessionHasErrorsIn('preferences', ['preferred_brands' => 'Choose up to 5 brands.']);
    }
}
