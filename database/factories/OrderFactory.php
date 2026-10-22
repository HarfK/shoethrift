<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_number' => 'TS-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'total' => 0,
            'shipping_fee' => 0,
            'status' => OrderStatus::Pending,
            'shipping_name' => fake()->name(),
            'shipping_phone' => fake()->numerify('01#-### ####'),
            'shipping_address' => fake()->address(),
        ];
    }

    /**
     * Indicate that the order has the given status.
     */
    public function status(OrderStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * An online (HitPay) order awaiting payment, with its payment window.
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethod::Online,
            'payment_expires_at' => now()->addMinutes((int) config('services.hitpay.payment_window_minutes')),
        ]);
    }

    public function cashOnDelivery(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethod::CashOnDelivery,
        ]);
    }
}
