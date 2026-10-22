<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Seed the store's shoe categories. Names must match ProductFactory::CATALOG.
     */
    public function run(): void
    {
        $categories = [
            'Sneakers' => 'Lifestyle and basketball-inspired sneakers.',
            'Running' => 'Running and training shoes.',
            'Casual' => 'Everyday canvas, suede and low-top shoes.',
            'Boots' => 'Leather boots and work boots.',
            'Formal' => 'Oxfords and dress shoes.',
            'Sandals' => 'Sandals, slides and clogs.',
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
