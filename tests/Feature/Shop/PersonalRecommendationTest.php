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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Personal recommendations: PersonalScore (0-100), the hybrid "You may also like"
 * (0.6 x similarity + 0.4 x personal), the home page "For you", interaction logging and the
 * shoe preferences form.
 *
 * The viewed pair is a Like-new Nike sneaker, UK 8, RM 300. Candidates start with nothing in
 * common with it (UK 12, Boots, Vans, RM 1000, Fair) and change only what a test is about.
 */
class PersonalRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $viewed;

    private Category $sneakers;

    private Category $boots;

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
            'brand' => 'Vans', 'size' => 12, 'category_id' => $this->boots->id, 'price' => 1000,
            'condition' => ProductCondition::Fair, 'stock' => 1, 'status' => ProductStatus::Available,
            ...$attributes,
        ]);
    }

    private function interact(Product $product, string $type, ?User $user = null): void
    {
        UserInteraction::create(['user_id' => ($user ?? $this->user)->id, 'product_id' => $product->id, 'type' => $type]);
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
     * @return list<int>
     */
    private function recommendedIds(?User $user): array
    {
        return app(Recommender::class)->forProduct($this->viewed, $user)->pluck('id')->all();
    }

    // --- Personal score: each part ----------------------------------------------

    public function test_no_data_scores_zero_in_every_part(): void
    {
        $profile = app(PersonalScore::class)->profileFor($this->user);

        $this->assertFalse($profile->hasData);
        $this->assertSame(['size' => 0.0, 'brand' => 0.0, 'category' => 0.0, 'budget' => 0.0], $this->personal($this->candidate())['parts']);
    }

    public function test_size_40_for_the_saved_size(): void
    {
        $this->prefer(['shoe_size' => 8.5]);

        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 8.5]))['parts']['size']);
        $this->assertSame(0.0, $this->personal($this->candidate(['size' => 9]))['parts']['size']);
    }

    public function test_without_a_saved_size_the_most_frequent_interacted_size_is_used(): void
    {
        // Two views of UK 9 beat one purchase of UK 10: the count decides, not the weight.
        $this->interact($this->candidate(['size' => 9]), 'view');
        $this->interact($this->candidate(['size' => 9]), 'view');
        $this->interact($this->candidate(['size' => 10]), 'purchase');

        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 9]))['parts']['size']);
        $this->assertSame(0.0, $this->personal($this->candidate(['size' => 10]))['parts']['size']);

        // A saved size wins over history.
        $this->prefer(['shoe_size' => 10]);
        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 10]))['parts']['size']);
    }

    public function test_equal_counts_go_to_the_higher_weight_then_the_smaller_size(): void
    {
        $this->interact($this->candidate(['size' => 9]), 'view');
        $this->interact($this->candidate(['size' => 10]), 'cart');
        $this->assertSame(40.0, $this->personal($this->candidate(['size' => 10]))['parts']['size']);

        UserInteraction::query()->delete();
        $this->interact($this->candidate(['size' => 9.5]), 'view');
        $this->interact($this->candidate(['size' => 7.5]), 'view');
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
        $this->prefer(['preferred_brands' => ['Puma']]);

        $this->assertSame(20.0, $this->personal($this->candidate(['brand' => 'puma']))['parts']['brand']);
        $this->assertSame(20.0, $this->personal($this->candidate(['brand' => 'Nike']))['parts']['brand']);
    }

    public function test_category_uses_the_same_weighted_affinity(): void
    {
        $this->interact($this->candidate(['category_id' => $this->sneakers->id]), 'view'); // 1
        $this->interact($this->candidate(['category_id' => $this->boots->id]), 'cart');    // 3 (highest)

        $this->assertSame(20.0, $this->personal($this->candidate(['category_id' => $this->boots->id]))['parts']['category']);
        $this->assertSame(6.67, $this->personal($this->candidate(['category_id' => $this->sneakers->id]))['parts']['category']);
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

    public function test_without_a_saved_budget_the_average_interacted_price_plus_minus_30_percent_is_used(): void
    {
        $this->interact($this->candidate(['price' => 100]), 'view');
        $this->interact($this->candidate(['price' => 300]), 'purchase'); // average RM 200 → RM 140 to 260

        foreach ([[140, 20.0], [260, 20.0], [139.99, 0.0], [260.01, 0.0]] as [$price, $points]) {
            $this->assertSame($points, $this->personal($this->candidate(['price' => $price]))['parts']['budget'], "RM {$price}");
        }
    }

    public function test_all_parts_add_up_to_100(): void
    {
        $this->prefer(['shoe_size' => 9, 'preferred_brands' => ['Adidas'], 'min_price' => 100, 'max_price' => 300]);
        $this->interact($this->candidate(['category_id' => $this->sneakers->id]), 'view');

        $result = $this->personal($this->candidate(['size' => 9, 'brand' => 'Adidas', 'category_id' => $this->sneakers->id, 'price' => 200]));
        $this->assertSame(100.0, $result['score']);
    }

    // --- Only the customer's own data -------------------------------------------

    public function test_only_the_users_own_preferences_and_interactions_are_used(): void
    {
        $other = User::factory()->create();
        $this->prefer(['shoe_size' => 12, 'preferred_brands' => ['Vans']], $other);
        $this->interact($this->candidate(['brand' => 'Vans']), 'purchase', $other);

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

    public function test_final_score_is_60_percent_similarity_plus_40_percent_personal(): void
    {
        $this->prefer(['shoe_size' => 12]);
        // Same category and brand (44.44) and the user's size (personal 40): 0.6 x 44.44 + 0.4 x 40 = 42.66.
        $forUser = $this->candidate(['name' => 'Nike Boot UK 12', 'brand' => 'Nike', 'category_id' => $this->sneakers->id]);
        // Same size and category (55.56) but not the user's size: 0.6 x 55.56 = 33.34, below 40.
        $forGuest = $this->candidate(['name' => 'Vans UK 8', 'size' => 8, 'category_id' => $this->sneakers->id]);

        $recommendations = app(Recommender::class)->forProduct($this->viewed, $this->user);
        $this->assertSame([$forUser->id], $recommendations->pluck('id')->all());
        $this->assertSame([42.66, 44.44, 40.0], [
            $recommendations->first()->recommendation_score,
            $recommendations->first()->similarity_score,
            $recommendations->first()->personal_score,
        ]);
        $this->assertContains('Your size', $recommendations->first()->recommendation_reasons);

        // Guests are unaffected by the user's data.
        $this->assertSame([$forGuest->id, $forUser->id], $this->recommendedIds(null));
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

    public function test_unavailable_sold_viewed_and_in_cart_products_are_left_out(): void
    {
        $match = ['size' => 8, 'category_id' => $this->sneakers->id];
        $this->candidate([...$match, 'status' => ProductStatus::Sold, 'stock' => 0]);
        $this->candidate([...$match, 'status' => ProductStatus::Hidden]);
        $this->candidate([...$match, 'stock' => 0]);
        $inCart = $this->candidate($match);
        $expiredLine = $this->candidate($match);
        $ok = $this->candidate($match);

        $cart = Cart::create(['user_id' => $this->user->id]);
        $cart->items()->create(['product_id' => $inCart->id, 'quantity' => 1, 'reserved_until' => now()->addMinutes(10)]);
        $cart->items()->create(['product_id' => $expiredLine->id, 'quantity' => 1, 'reserved_until' => now()->subMinute()]);

        $this->assertEqualsCanonicalizing([$ok->id, $expiredLine->id], $this->recommendedIds($this->user));
        $this->assertNotContains($this->viewed->id, $this->recommendedIds($this->user));
        $this->assertContains($inCart->id, $this->recommendedIds(null), 'Only the cart owner skips it.');
    }

    public function test_product_page_marks_personal_picks_and_tags_links(): void
    {
        $this->prefer(['shoe_size' => 12]);
        $pick = $this->candidate(['name' => 'Nike Boot UK 12', 'slug' => 'nike-boot-uk-12', 'brand' => 'Nike', 'category_id' => $this->sneakers->id]);

        $this->actingAs($this->user)->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee(['You may also like', 'Also based on your shoe preferences', 'Nike Boot UK 12', 'Same category · Same brand · Your size'])
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
        $budgetOnly = $this->candidate(['name' => 'Budget only', 'price' => 100]);                   // 20
        $this->candidate(['name' => 'Nothing for me']);                                              // 0
        $inCart = $this->candidate(['name' => 'Already in cart', 'size' => 9, 'price' => 150]);
        Cart::create(['user_id' => $this->user->id])->items()->create(['product_id' => $inCart->id, 'quantity' => 1, 'reserved_until' => now()->addMinutes(10)]);

        $forYou = app(Recommender::class)->forYou($this->user);
        $this->assertSame([$sizeAndBudget->id, $sizeOnly->id, $budgetOnly->id], $forYou->pluck('id')->all());
        $this->assertSame(['Your size', 'In your budget'], $forYou->first()->recommendation_reasons);

        $this->actingAs($this->user)->get(route('home'))
            ->assertSee('data-for-you', false)
            ->assertSeeInOrder(['For you', 'Size and budget', 'Size only', 'Budget only', 'Newly added'])
            ->assertSee(route('shop.show', ['slug' => $sizeOnly->slug, 'src' => 'for_you']), false);
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
