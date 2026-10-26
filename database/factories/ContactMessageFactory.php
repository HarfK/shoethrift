<?php

namespace Database\Factories;

use App\Enums\ContactTopic;
use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'topic' => fake()->randomElement(ContactTopic::cases()),
            'message' => fake()->paragraph(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['read_at' => now(), 'resolved_at' => now()]);
    }
}
