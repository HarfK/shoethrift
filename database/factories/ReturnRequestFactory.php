<?php

namespace Database\Factories;

use App\Enums\ReturnReason;
use App\Models\Order;
use App\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnRequest>
 */
class ReturnRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'user_id' => fn (array $attributes) => Order::find($attributes['order_id'])->user_id,
            'reason' => fake()->randomElement(ReturnReason::cases()),
            'details' => fake()->sentence(12),
        ];
    }
}
