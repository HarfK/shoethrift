<?php

namespace Database\Seeders;

use App\Models\ContactMessage;
use Illuminate\Database\Seeder;

class ContactMessageSeeder extends Seeder
{
    /**
     * A few Customer Service messages so the admin Messages page isn't empty.
     */
    public function run(): void
    {
        ContactMessage::factory()->count(3)->create();
        ContactMessage::factory()->count(2)->resolved()->create(['created_at' => now()->subDays(10)]);
    }
}
