<?php

namespace Tests\Feature\Reviews;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $dunk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['name' => 'Aisyah binti Rahman']);
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'slug' => 'nike-dunk-low', 'size' => 8.5, 'stock' => 0, 'status' => ProductStatus::Sold]);
    }

    /**
     * An order of the Dunk (default: a completed online order).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function purchase(array $attributes = [], ?User $buyer = null, ?Product $product = null): OrderItem
    {
        $order = Order::factory()->for($buyer ?? $this->customer)->create([
            'status' => OrderStatus::Completed,
            'payment_method' => PaymentMethod::Online,
            'paid_at' => now()->subDays(3),
            'delivered_at' => now()->subDay(),
            'total' => 310,
            ...$attributes,
        ]);

        return OrderItem::factory()->for($order)->for($product ?? $this->dunk)->create(['price' => 300]);
    }

    private function review(OrderItem $item, array $fields = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->customer)->post(route('account.reviews.store', [$item->order->order_number, $item->id]), [
            'rating' => 4,
            'condition_rating' => 5,
            'comment' => 'Fits true to size and the condition matches the photos.',
            ...$fields,
        ]);
    }

    // --- Who can review ----------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function eligibleOrders(): array
    {
        return [
            'online paid' => [['status' => OrderStatus::Paid, 'delivered_at' => null]],
            'online shipped' => [['status' => OrderStatus::Shipped, 'delivered_at' => null]],
            'online completed' => [[]],
            'COD delivered (cash not yet collected)' => [['status' => OrderStatus::Delivered, 'payment_method' => PaymentMethod::CashOnDelivery, 'paid_at' => null]],
            'COD completed' => [['payment_method' => PaymentMethod::CashOnDelivery]],
            'partially refunded' => [['refunded_amount' => 50]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('eligibleOrders')]
    public function test_buyers_of_paid_or_delivered_orders_can_review(array $attributes): void
    {
        $item = $this->purchase($attributes);

        $this->actingAs($this->customer)->get(route('account.orders.show', $item->order->order_number))->assertSee('Write a review');
        $this->actingAs($this->customer)->get(route('account.reviews.create', [$item->order->order_number, $item->id]))->assertOk()->assertSee('Nike Dunk Low');

        $this->review($item, ['rating' => 5])
            ->assertRedirect(route('account.orders.show', $item->order->order_number))
            ->assertSessionHas('success', 'Thanks for reviewing Nike Dunk Low!');

        $review = Review::sole();
        $this->assertSame('5.0', $review->rating);
        $this->assertSame([$this->customer->id, $this->dunk->id, $item->order_id], [$review->user_id, $review->product_id, $review->order_id]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function ineligibleOrders(): array
    {
        return [
            'pending online' => [['status' => OrderStatus::Pending, 'paid_at' => null, 'delivered_at' => null]],
            'COD pending' => [['status' => OrderStatus::Pending, 'payment_method' => PaymentMethod::CashOnDelivery, 'paid_at' => null, 'delivered_at' => null]],
            'COD shipped, not delivered' => [['status' => OrderStatus::Shipped, 'payment_method' => PaymentMethod::CashOnDelivery, 'paid_at' => null, 'delivered_at' => null]],
            'cancelled' => [['status' => OrderStatus::Cancelled]],
            'failed' => [['status' => OrderStatus::Failed, 'paid_at' => null]],
            'refunded' => [['status' => OrderStatus::Refunded, 'refunded_amount' => 310]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('ineligibleOrders')]
    public function test_other_orders_cannot_be_reviewed(array $attributes): void
    {
        $item = $this->purchase($attributes);

        $this->actingAs($this->customer)->get(route('account.orders.show', $item->order->order_number))->assertDontSee('Write a review');
        $this->actingAs($this->customer)->get(route('account.reviews.create', [$item->order->order_number, $item->id]))
            ->assertRedirect(route('account.orders.show', $item->order->order_number))
            ->assertSessionHas('error', 'You can review pairs once your order is paid or delivered.');
        $this->review($item)->assertSessionHas('error');

        $this->assertSame(0, Review::count());
    }

    public function test_returned_and_refunded_pairs_cannot_be_reviewed(): void
    {
        $item = $this->purchase();
        $kept = OrderItem::factory()->for($item->order)->for(Product::factory()->create(['name' => 'Adidas Samba OG']))->create();
        $return = ReturnRequest::factory()->create(['order_id' => $item->order_id]);
        $return->items()->create(['order_item_id' => $item->id, 'quantity' => 1]);
        $return->forceFill(['status' => ReturnStatus::Refunded])->save();
        $item->order->update(['refunded_amount' => 150]);

        $this->review($item)->assertSessionHas('error');
        $this->review($kept)->assertSessionHas('success');

        $this->assertSame([$kept->product_id], Review::pluck('product_id')->all());
    }

    public function test_someone_elses_order_or_an_item_from_another_order_is_404(): void
    {
        $theirs = $this->purchase(buyer: User::factory()->create());
        $mine = $this->purchase();

        $this->review($theirs)->assertNotFound();
        $this->actingAs($this->customer)->post(route('account.reviews.store', [$mine->order->order_number, $theirs->id]), ['rating' => 5, 'condition_rating' => 5, 'comment' => 'Trying another order item.'])
            ->assertNotFound();

        $this->assertSame(0, Review::count());
    }

    // --- Once per product per order ----------------------------------------

    public function test_one_review_per_product_per_order(): void
    {
        $item = $this->purchase();

        $this->review($item)->assertSessionHas('success');
        $this->review($item, ['rating' => 1])->assertSessionHas('error', 'You have already reviewed this pair for this order.');

        $this->assertSame(1, Review::count());
        $this->actingAs($this->customer)->get(route('account.orders.show', $item->order->order_number))
            ->assertSee('Your review:')
            ->assertDontSee('Write a review');
    }

    public function test_the_same_product_bought_in_two_orders_can_be_reviewed_twice(): void
    {
        $this->dunk->update(['stock' => 0]);
        $first = $this->purchase();
        $second = $this->purchase();

        $this->review($first)->assertSessionHas('success');
        $this->review($second, ['rating' => 2])->assertSessionHas('success');

        $this->assertSame(2, Review::where('product_id', $this->dunk->id)->count());
    }

    public function test_review_is_validated(): void
    {
        $item = $this->purchase();

        $this->review($item, ['rating' => 6, 'comment' => 'Too short'])->assertSessionHasErrors(['rating', 'comment']);
        $this->review($item, ['rating' => 0])->assertSessionHasErrors(['rating' => 'Choose between half a star and 5 stars.']);
        $this->review($item, ['rating' => '', 'comment' => str_repeat('a', 1001)])->assertSessionHasErrors(['rating' => 'Choose a star rating.', 'comment']);

        $this->assertSame(0, Review::count());
    }

    // --- Display -------------------------------------------------------------

    private function reviewsFor(Product $product, array $ratings, array $state = []): void
    {
        foreach ($ratings as $rating) {
            $order = Order::factory()->create(['status' => OrderStatus::Completed, 'paid_at' => now()]);
            Review::factory()->for($order)->for($product)->create(['rating' => $rating, 'user_id' => $order->user_id, ...$state]);
        }
    }

    public function test_product_page_shows_average_breakdown_and_reviews(): void
    {
        $this->reviewsFor($this->dunk, [5, 4, 4]);
        $this->reviewsFor($this->dunk, [1], ['hidden_at' => now(), 'comment' => 'Spam spam spam spam']);
        Review::query()->oldest('id')->first()->user->update(['name' => 'Aisyah binti Rahman']);

        $response = $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertOk()
            ->assertSee(['4.3 · 3 reviews', 'Rated 4.3 out of 5', '3 reviews from verified buyers', 'Verified purchase', 'Aisyah R.', 'bought in UK 8.5'])
            ->assertDontSee(['Spam spam spam', 'Aisyah binti Rahman']);

        $this->assertSame([5 => 1, 4 => 2, 3 => 0, 2 => 0, 1 => 0], $response->viewData('ratingBreakdown')->all());
    }

    public function test_reviews_are_newest_first_and_paginated(): void
    {
        foreach (range(1, 12) as $i) {
            $order = Order::factory()->create(['status' => OrderStatus::Completed, 'paid_at' => now()]);
            Review::factory()->for($order)->for($this->dunk)->create([
                'user_id' => $order->user_id,
                'comment' => sprintf('Review number %02d for this pair.', $i),
                'created_at' => now()->addMinutes($i),
            ]);
        }

        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSeeInOrder(['Review number 12', 'Review number 11'])
            ->assertDontSee('Review number 02')
            ->assertSee('reviews_page=2', false);

        $this->get(route('shop.show', ['nike-dunk-low', 'reviews_page' => 2]))->assertSee(['Review number 02', 'Review number 01']);
    }

    public function test_product_without_reviews(): void
    {
        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee('No reviews for this pair yet.')->assertDontSee('Rated ');
    }

    public function test_product_cards_show_rating_and_count_from_visible_reviews(): void
    {
        $samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'stock' => 1]);
        $this->reviewsFor($samba, [5, 4]);
        $this->reviewsFor($samba, [1], ['hidden_at' => now()]);
        Product::factory()->create(['name' => 'Unreviewed Pair', 'stock' => 1]);

        foreach ([route('shop.index'), route('home')] as $url) {
            $this->get($url)->assertSee(['Rated 4.5 out of 5 from 2 reviews', '(2)']);
        }

        $product = Product::withRatingSummary()->find($samba->id);
        $this->assertSame(2, $product->reviews_count);
        $this->assertEquals(4.5, $product->rating_avg);
    }

    public function test_product_page_invites_eligible_buyers_to_review(): void
    {
        $item = $this->purchase();

        $this->actingAs($this->customer)->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('You bought this pair: write a review')
            ->assertSee(route('account.reviews.create', [$item->order->order_number, $item->id]), false);

        $this->review($item);
        $this->actingAs($this->customer)->get(route('shop.show', 'nike-dunk-low'))->assertDontSee('You bought this pair');
        $this->actingAs(User::factory()->create())->get(route('shop.show', 'nike-dunk-low'))->assertDontSee('You bought this pair');
    }

    // --- Admin -------------------------------------------------------------

    public function test_new_reviews_notify_admins(): void
    {
        $admin = User::factory()->admin()->create();

        $this->review($this->purchase(), ['rating' => 2]);

        $data = $admin->notifications()->sole()->data;
        $this->assertSame('review', $data['kind']);
        $this->assertSame('New 2★ review on Nike Dunk Low (flagged)', $data['title']);
    }

    public function test_admin_hides_unhides_and_deletes_reviews(): void
    {
        $admin = User::factory()->admin()->create();
        $this->reviewsFor($this->dunk, [5, 1]);
        $bad = Review::firstWhere('rating', 1);

        $this->actingAs($admin)->get(route('admin.reviews.index'))->assertOk()->assertSee(['Nike Dunk Low', 'Hide', 'Delete']);
        $this->actingAs($admin)->get(route('admin.reviews.index', ['rating' => 1]))->assertViewHas('reviews', fn ($reviews) => $reviews->pluck('id')->all() === [$bad->id]);

        $this->actingAs($admin)->patch(route('admin.reviews.toggle', $bad))->assertSessionHas('success');
        $this->assertTrue($bad->fresh()->isHidden());
        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee('5.0 · 1 review');
        $this->actingAs($admin)->get(route('admin.reviews.index', ['visibility' => 'hidden']))->assertSee('Unhide');

        $this->actingAs($admin)->patch(route('admin.reviews.toggle', $bad));
        $this->assertFalse($bad->fresh()->isHidden());
        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee('3.0 · 2 reviews');

        $this->actingAs($admin)->delete(route('admin.reviews.destroy', $bad))->assertSessionHas('success', 'Review deleted.');
        $this->assertModelMissing($bad);
    }

    public function test_customer_sees_when_their_review_is_hidden(): void
    {
        $item = $this->purchase();
        $this->review($item);
        Review::sole()->forceFill(['hidden_at' => now()])->save();

        $this->actingAs($this->customer)->get(route('account.orders.show', $item->order->order_number))->assertSee('(hidden by ShoeThrift)');
    }

    public function test_customers_cannot_moderate(): void
    {
        $this->reviewsFor($this->dunk, [3]);
        $review = Review::sole();

        $this->actingAs($this->customer)->get(route('admin.reviews.index'))->assertForbidden();
        $this->actingAs($this->customer)->patch(route('admin.reviews.toggle', $review))->assertForbidden();
        $this->actingAs($this->customer)->delete(route('admin.reviews.destroy', $review))->assertForbidden();
        $this->assertModelExists($review);
    }
}
