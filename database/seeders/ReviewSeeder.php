<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Review;
use Illuminate\Database\Seeder;

class ReviewSeeder extends Seeder
{
    /**
     * Reviews on most seeded orders that allow them (Order::allowsReviews()), so product
     * cards and pages show ratings. customer@thriftstore.test's orders are left unreviewed
     * so the "Write a review" flow can be tried.
     */
    public function run(): void
    {
        $orders = Order::with(['items', 'user'])->get()
            ->filter(fn (Order $order) => $order->allowsReviews() && $order->user->email !== 'customer@thriftstore.test');

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (fake()->boolean(75)) {
                    $review = Review::factory()->make(['rating' => fake()->randomElement([5.0, 5.0, 4.5, 4.5, 4.0, 4.0, 3.5, 3.0, 2.0])]);
                    $review->forceFill([
                        'user_id' => $order->user_id,
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'created_at' => ($order->delivered_at ?? $order->paid_at ?? $order->created_at)->copy()->addDays(2),
                    ])->save();
                }
            }
        }
    }
}
