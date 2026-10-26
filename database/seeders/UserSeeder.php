<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed one admin and a set of customers. All seeded accounts have a verified email and phone
     * (so checkout works straight away), except unverified@thriftstore.test, which is there to
     * demo email and phone verification.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Store Admin',
            'email' => 'admin@thriftstore.test',
            'phone_verified_at' => now(),
        ]);

        $customer = User::factory()->create([
            'name' => 'Test Customer',
            'email' => 'customer@thriftstore.test',
            'phone_verified_at' => now(),
        ]);
        Address::factory()->for($customer)->default()->create(['label' => 'Home', 'recipient_name' => $customer->name, 'phone' => $customer->phone]);
        Address::factory()->for($customer)->create(['label' => 'Office', 'recipient_name' => $customer->name, 'phone' => $customer->phone]);

        // Other customers each get a default address.
        User::factory(9)->create(['phone_verified_at' => now()])->each(fn (User $user) => Address::factory()->for($user)->default()
            ->create(['recipient_name' => $user->name, 'phone' => $user->phone]));

        // An unverified account, to try the email verification flow (link is in storage/logs/laravel.log).
        User::factory()->unverified()->create([
            'name' => 'New Customer',
            'email' => 'unverified@thriftstore.test',
        ]);
    }
}
