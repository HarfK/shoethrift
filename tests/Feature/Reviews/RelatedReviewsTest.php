<?php

namespace Tests\Feature\Reviews;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\ReviewStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Product pages without reviews of their own show the store rating and "What buyers say about"
 * the brand (≥ 3 reviews of other pairs) or else the category.
 */
class RelatedReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Category $sneakers;

    private Product $viewed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sneakers = Category::factory()->create(['name' => 'Sneakers', 'slug' => 'sneakers']);
        $this->viewed = $this->pair('Nike Air Force 1', 'Nike', ['slug' => 'nike-af1', 'stock' => 1, 'status' => ProductStatus::Available]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pair(string $name, string $brand, array $attributes = []): Product
    {
        return Product::factory()->create([
            'name' => $name, 'brand' => $brand, 'category_id' => $this->sneakers->id,
            'stock' => 0, 'status' => ProductStatus::Sold, ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function review(Product $product, string $rating = '4.0', array $attributes = [], ?User $buyer = null): Review
    {
        $order = Order::factory()->for($buyer ?? User::factory()->create())->create(['status' => OrderStatus::Completed, 'paid_at' => now()]);

        return Review::factory()->for($order)->for($product)->create([
            'user_id' => $order->user_id, 'rating' => $rating, 'condition_rating' => 5, ...$attributes,
        ]);
    }

    private function page()
    {
        return $this->get(route('shop.show', 'nike-af1'))->assertOk();
    }

    // --- Own reviews first ----------------------------------------------------------

    public function test_own_reviews_come_first_and_there_is_no_fallback_block(): void
    {
        $this->review($this->viewed, '5.0', ['comment' => 'My own pair review text here.']);
        foreach (range(1, 3) as $i) {
            $this->review($this->pair("Nike Other {$i}", 'Nike'), '3.0', ['comment' => "Other Nike review {$i} text."]);
        }

        $this->page()
            ->assertSee(['My own pair review text here.', '5.0 · 1 review'])
            ->assertDontSee(['No reviews for this pair yet', 'What buyers say about', 'Other Nike review']);
    }

    public function test_a_sold_product_still_shows_its_reviews(): void
    {
        $this->viewed->update(['status' => ProductStatus::Sold, 'stock' => 0]);
        $this->review($this->viewed, '4.5', ['comment' => 'Sold pair review is still here.']);

        $this->page()->assertSee(['Sold pair review is still here.', 'this pair has found a new home'])->assertDontSee('No reviews for this pair yet');
    }

    // --- Fallback ---------------------------------------------------------------------

    public function test_brand_block_with_store_rating_when_the_pair_has_no_reviews(): void
    {
        $older = $this->pair('Nike Dunk Low', 'nike', ['slug' => 'nike-dunk-low']); // brand matched case-insensitively
        $this->review($older, '5.0', ['comment' => 'Oldest Nike review text.', 'created_at' => now()->subDays(4), 'condition_rating' => 4]);
        foreach (['Newest', 'Second', 'Third'] as $i => $label) {
            $this->review($this->pair("Nike {$label}", 'Nike'), '4.0', ['comment' => "{$label} Nike review text.", 'created_at' => now()->subDays($i)]);
        }
        $this->review($this->pair('Adidas Samba', 'Adidas'), '1.0', ['comment' => 'An Adidas review text.']);

        $this->page()
            ->assertSee(['No reviews for this pair yet.', 'store rating', route('reviews.index'), 'Read all reviews'])
            ->assertSee('data-related-reviews="brand"', false)
            ->assertSee(['What buyers say about Nike', '4.3', 'from 4 reviews', 'Condition accuracy 4.8/5'])
            ->assertSeeInOrder(['Newest Nike review', 'Second Nike review', 'Third Nike review'])
            ->assertDontSee(['Oldest Nike review', 'An Adidas review'])
            ->assertSee(route('shop.show', Product::firstWhere('name', 'Nike Newest')->slug).'#reviews', false);

        // Store line counts every public review (5 reviews: 5, 4, 4, 4, 1 → 3.6).
        $this->page()->assertSee(['3.6', 'from 5 reviews']);
    }

    public function test_falls_back_to_the_category_when_the_brand_has_fewer_than_three_reviews(): void
    {
        $this->review($this->pair('Nike One', 'Nike'), '5.0', ['comment' => 'Only Nike review one.']);
        $this->review($this->pair('Nike Two', 'Nike'), '5.0', ['comment' => 'Only Nike review two.']);
        $this->review($this->pair('Adidas Samba', 'Adidas'), '2.0', ['comment' => 'A sneaker review from Adidas.']);
        $boots = Category::factory()->create(['name' => 'Boots']);
        $this->review($this->pair('Dr. Martens 1460', 'Dr. Martens', ['category_id' => $boots->id]), '1.0', ['comment' => 'A boots review text.']);

        $this->page()
            ->assertSee('data-related-reviews="category"', false)
            ->assertSee(['What buyers say about Sneakers', '4.0', 'from 3 reviews', 'A sneaker review from Adidas'])
            ->assertDontSee('A boots review text.');
    }

    public function test_only_the_store_rating_when_brand_and_category_have_no_reviews(): void
    {
        $boots = Category::factory()->create(['name' => 'Boots']);
        $this->review($this->pair('Boot', 'Dr. Martens', ['category_id' => $boots->id]), '4.0');

        $this->page()
            ->assertSee(['No reviews for this pair yet.', 'store rating', 'from 1 review'])
            ->assertDontSee('What buyers say about');
    }

    public function test_hidden_reviews_and_hidden_products_never_count(): void
    {
        $this->review($this->viewed, '1.0', ['comment' => 'Hidden own review text.', 'hidden_at' => now()]);
        foreach (range(1, 3) as $i) {
            $this->review($this->pair("Nike {$i}", 'Nike'), '5.0', ['comment' => "Visible Nike review {$i}."]);
        }
        $this->review($this->pair('Nike Hidden Review', 'Nike'), '1.0', ['comment' => 'Hidden Nike review text.', 'hidden_at' => now()]);
        $this->review($this->pair('Nike Hidden Pair', 'Nike', ['status' => ProductStatus::Hidden]), '1.0', ['comment' => 'Review on a hidden pair.']);

        $this->page()
            ->assertSee(['No reviews for this pair yet.', 'What buyers say about Nike', '5.0', 'from 3 reviews'])
            ->assertDontSee(['Hidden own review', 'Hidden Nike review', 'Review on a hidden pair']);

        $this->assertSame(3, ReviewStats::store()['count']);
        $this->get(route('reviews.index'))->assertDontSee(['Hidden own review', 'Hidden Nike review', 'Review on a hidden pair']);
    }

    // --- Privacy --------------------------------------------------------------------

    public function test_no_personal_data_is_shown(): void
    {
        $buyer = User::factory()->create([
            'name' => 'Aisyah binti Rahman', 'email' => 'aisyah.private@example.com',
            'phone' => '019-876 5432', 'address' => 'No. 99, Jalan Rahsia',
        ]);
        $buyer->addresses()->create(['recipient_name' => 'Aisyah binti Rahman', 'phone' => '019-876 5432', 'line1' => 'No. 99, Jalan Rahsia', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor']);
        foreach (range(1, 3) as $i) {
            $this->review($this->pair("Nike {$i}", 'Nike'), '4.0', buyer: $buyer);
        }

        foreach ([route('shop.show', 'nike-af1'), route('reviews.index')] as $url) {
            $this->get($url)
                ->assertSee(['Aisyah R.', 'Verified purchase'])
                ->assertDontSee(['aisyah.private@example.com', '019-876 5432', 'Jalan Rahsia', 'binti Rahman', 'Rahman']);
        }
    }

    // --- Condition accuracy ------------------------------------------------------------

    public function test_condition_accuracy_on_the_store_page_ignores_reviews_without_it(): void
    {
        $this->review($this->pair('A', 'Nike'), '4.0', ['condition_rating' => 5]);
        $this->review($this->pair('B', 'Nike'), '4.0', ['condition_rating' => 4]);
        $this->review($this->pair('C', 'Nike'), '4.0', ['condition_rating' => null]); // written before the question existed

        $this->get(route('reviews.index'))
            ->assertSee(['Condition accuracy', '4.5/5', 'Condition matched: 5/5']);
    }

    // --- Cache and queries ---------------------------------------------------------------

    public function test_aggregates_are_cached_and_refreshed_when_reviews_change(): void
    {
        $this->review($this->pair('A', 'Nike'), '4.0');
        $this->assertSame(1, ReviewStats::store()['count']);

        // Cached: a raw insert that bypasses the model isn't seen…
        DB::table('reviews')->insert(['user_id' => User::factory()->create()->id, 'product_id' => $this->pair('Raw', 'Nike')->id,
            'order_id' => Order::factory()->create()->id, 'rating' => 1, 'comment' => 'Inserted directly.', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, ReviewStats::store()['count']);
        $this->assertTrue(Cache::has('reviews:store'));

        // …but any model change clears it.
        $review = $this->review($this->pair('B', 'Nike'), '5.0');
        $this->assertSame(3, ReviewStats::store()['count']);
        $this->assertSame(3, ReviewStats::brand('Nike', $this->viewed->id)['count'], 'Brand cache was refreshed too (A, the raw row, B).');

        $review->forceFill(['hidden_at' => now()])->save();
        $this->assertSame(2, ReviewStats::store()['count']);

        $review->delete();
        $this->assertSame(2, ReviewStats::store()['count']);
    }

    public function test_brand_and_category_caches_refresh_after_a_change(): void
    {
        foreach (range(1, 3) as $i) {
            $this->review($this->pair("Nike {$i}", 'Nike'), '4.0');
        }
        $this->page()->assertSee('from 3 reviews');

        $this->review($this->pair('Nike 4', 'Nike'), '5.0');
        $this->page()->assertSee('from 4 reviews');
    }

    public function test_product_page_query_count_does_not_grow_with_more_reviews(): void
    {
        foreach (range(1, 3) as $i) {
            $this->review($this->pair("Nike {$i}", 'Nike'), '4.0');
        }
        $this->page();
        Cache::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page();
        $withThree = count(DB::getQueryLog());

        foreach (range(4, 12) as $i) {
            $this->review($this->pair("Nike {$i}", 'Nike'), '4.0');
        }
        Cache::flush();
        DB::flushQueryLog();
        $this->page();
        $withTwelve = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($withThree, $withTwelve, "The product page shouldn't run more queries as related reviews grow (N+1).");
    }
}
