<?php

namespace Tests\Feature\Reviews;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

/**
 * A customer who bought the Nike Dunk Low in a completed order, plus helpers to post reviews.
 */
trait BuildsReviews
{
    private User $customer;

    private Product $dunk;

    private OrderItem $item;

    private function setUpPurchase(): void
    {
        $this->customer = User::factory()->create(['name' => 'Aisyah Rahman']);
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'slug' => 'nike-dunk-low', 'size' => 8.5, 'stock' => 0, 'status' => ProductStatus::Sold]);
        $this->item = $this->purchase();
    }

    private function purchase(?User $buyer = null, ?Product $product = null): OrderItem
    {
        $order = Order::factory()->for($buyer ?? $this->customer)->create([
            'status' => OrderStatus::Completed, 'payment_method' => PaymentMethod::Online,
            'paid_at' => now()->subDays(3), 'delivered_at' => now()->subDay(), 'total' => 310,
        ]);

        return OrderItem::factory()->for($order)->for($product ?? $this->dunk)->create(['price' => 300]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function postReview(array $fields = [], ?OrderItem $item = null)
    {
        $item ??= $this->item;

        return $this->actingAs($this->customer)->post(route('account.reviews.store', [$item->order->order_number, $item->id]), [
            'rating' => '4.5',
            'condition_rating' => '5',
            'comment' => 'Fits true to size and the condition matches the photos.',
            ...$fields,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function updateReview(Review $review, array $fields = [])
    {
        return $this->actingAs($this->customer)->put(route('account.reviews.update', $review), [
            'rating' => (string) $review->rating,
            'condition_rating' => (string) ($review->condition_rating ?? 5),
            'comment' => $review->comment,
            ...$fields,
        ]);
    }
}
