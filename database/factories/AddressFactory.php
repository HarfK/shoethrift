<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Malaysian-style addresses (Faker's default locale produces American ones).
 *
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    private const PLACES = [
        ['40100', 'Shah Alam', 'Selangor'],
        ['47301', 'Petaling Jaya', 'Selangor'],
        ['50450', 'Kuala Lumpur', 'W.P. Kuala Lumpur'],
        ['10200', 'George Town', 'Pulau Pinang'],
        ['80000', 'Johor Bahru', 'Johor'],
        ['30000', 'Ipoh', 'Perak'],
        ['93000', 'Kuching', 'Sarawak'],
        ['88000', 'Kota Kinabalu', 'Sabah'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$postcode, $city, $state] = fake()->randomElement(self::PLACES);

        return [
            'user_id' => User::factory(),
            'label' => fake()->optional()->randomElement(['Home', 'Office', 'Parents']),
            'recipient_name' => fake()->name(),
            'phone' => fake()->numerify('01#-### ####'),
            'line1' => 'No. '.fake()->numberBetween(1, 200).', Jalan '.fake()->randomElement(['Bunga Raya', 'Melati', 'Kenanga', 'Cempaka', 'Mawar']).' '.fake()->numberBetween(1, 20),
            'line2' => fake()->optional()->randomElement(['Taman Melati', 'Taman Sri Muda', 'Seksyen 7', 'Bandar Baru']),
            'postcode' => $postcode,
            'city' => $city,
            'state' => $state,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
