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

        Product::factory(36)
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
