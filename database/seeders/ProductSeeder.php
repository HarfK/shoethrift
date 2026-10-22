<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Seed products, each with 1-3 images (exactly one primary).
     */
    public function run(): void
    {
        $categories = Category::all();

        // Enough stock that OrderSeeder (at most 15 orders x 2 items) can never sell out the store.
        Product::factory(50)
            ->recycle($categories)
            ->create()
            ->each(fn (Product $product) => $this->addImages($product));

        Product::factory(4)
            ->hidden()
            ->recycle($categories)
            ->create()
            ->each(fn (Product $product) => $this->addImages($product));
    }

    private function addImages(Product $product): void
    {
        ProductImage::factory()->primary()->for($product)->create();
        ProductImage::factory(fake()->numberBetween(0, 2))->for($product)->create();
    }
}
