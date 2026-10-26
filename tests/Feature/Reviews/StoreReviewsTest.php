<?php

namespace Tests\Feature\Reviews;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function review(Product $product, string $rating, array $state = []): Review
    {
        $order = Order::factory()->create(['status' => OrderStatus::Completed, 'paid_at' => now()]);

        return Review::factory()->for($order)->for($product)->create(['user_id' => $order->user_id, 'rating' => $rating, ...$state]);
    }

    public function test_store_page_shows_average_total_breakdown_and_newest_first(): void
    {
        $dunk = Product::factory()->create(['name' => 'Nike Dunk Low']);
        $samba = Product::factory()->create(['name' => 'Adidas Samba OG']);
        $this->review($dunk, '5.0', ['comment' => 'Oldest review here, still great.', 'created_at' => now()->subDays(3)]);
        $this->review($samba, '4.5', ['comment' => 'Newest review here, very happy.', 'created_at' => now()]);
        $this->review($dunk, '3.0', ['comment' => 'Middle review here, it was okay.', 'created_at' => now()->subDay()]);
        $this->review($samba, '1.0', ['comment' => 'Hidden spam review text here.', 'hidden_at' => now()]);

        $response = $this->get(route('reviews.index'))
            ->assertOk()
            ->assertSee(['Customer reviews', '4.2', '3 reviews', 'Verified purchase', 'Nike Dunk Low', 'Adidas Samba OG'])
            ->assertSeeInOrder(['Newest review here', 'Middle review here', 'Oldest review here'])
            ->assertDontSee('Hidden spam review');

        $this->assertSame([5 => 1, 4 => 1, 3 => 1, 2 => 0, 1 => 0], $response->viewData('breakdown'));
        $this->assertSame(4.2, $response->viewData('summary')['average']);
        $this->assertSame(3, $response->viewData('summary')['count']);
    }

    public function test_store_average_in_footer_and_home_page(): void
    {
        $product = Product::factory()->create();
        $this->review($product, '4.5');
        $this->review($product, '3.5');

        $this->get(route('home'))
            ->assertSee(['4.0', 'from 2 verified reviews', route('reviews.index')], false)
            ->assertSee('data-footer-rating', false)
            ->assertSee('data-home-rating', false);
        $this->get(route('pages.faq'))->assertSee(['from 2 reviews', 'Read reviews']);
    }

    public function test_no_reviews_yet(): void
    {
        $this->get(route('reviews.index'))->assertSee('No reviews yet.');
        $this->get(route('home'))->assertDontSee('data-footer-rating', false)->assertDontSee('data-home-rating', false);
    }

    public function test_product_page_keeps_its_own_average_and_count(): void
    {
        $dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'slug' => 'nike-dunk-low']);
        $other = Product::factory()->create();
        $this->review($dunk, '5.0');
        $this->review($other, '1.0');

        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee('5.0 · 1 review');
        $this->get(route('home'))->assertSee('3.0');
    }

    public function test_store_page_paginates(): void
    {
        $product = Product::factory()->create();
        foreach (range(1, 16) as $i) {
            $this->review($product, '4.0', ['comment' => sprintf('Store review number %02d text.', $i), 'created_at' => now()->addMinutes($i)]);
        }

        $this->get(route('reviews.index'))->assertDontSee('Store review number 01')->assertSee('?page=2', false);
        $this->get(route('reviews.index', ['page' => 2]))->assertSee('Store review number 01');
    }

    public function test_reviews_link_in_footer(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertSee('Customer reviews');
    }
}
