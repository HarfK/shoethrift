<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed one admin and a set of customers.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Store Admin',
            'email' => 'admin@thriftstore.test',
        ]);

        User::factory()->create([
            'name' => 'Test Customer',
            'email' => 'customer@thriftstore.test',
        ]);

        User::factory(9)->create();
    }
}
