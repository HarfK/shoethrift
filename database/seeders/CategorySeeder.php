<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Seed the store's product categories.
     */
    public function run(): void
    {
        $categories = [
            'Tops' => 'T-shirts, blouses, shirts and knitwear.',
            'Outerwear' => 'Jackets, coats and blazers for every season.',
            'Denim' => 'Pre-loved jeans, denim jackets and skirts.',
            'Dresses' => 'Casual, vintage and occasion dresses.',
            'Shoes' => 'Sneakers, boots, sandals and heels.',
            'Accessories' => 'Bags, scarves, belts and jewellery.',
        ];

        foreach ($categories as $name => $description) {
            Category::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $description,
            ]);
        }
    }
}
