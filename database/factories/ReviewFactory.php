<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'user_id' => fn (array $attributes) => Order::find($attributes['order_id'])->user_id,
            'product_id' => Product::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'comment' => fake()->randomElement([
                'Exactly as described, and the condition is even better in person.',
                'Fits true to size. Clean and well packed.',
                'Great pair for the price. Minor wear as shown in the photos.',
                'Arrived quickly and the soles have plenty of life left.',
            ]),
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['hidden_at' => now()]);
    }
}
