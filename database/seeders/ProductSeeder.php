<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Factories\ProductFactory;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Seed shoes from the factory catalog, each in its matching category with 1-3 images (exactly one primary).
     */
    public function run(): void
    {
        $categories = Category::all()->keyBy('name');

        // Enough stock that OrderSeeder (at most 15 orders x 2 items) can never sell out the store.
        for ($i = 0; $i < 54; $i++) {
            [$brand, $model, $category] = fake()->randomElement(ProductFactory::CATALOG);

            $product = Product::factory()
                ->when($i >= 50, fn (ProductFactory $factory) => $factory->hidden())
                ->create([
                    'category_id' => $categories[$category]->id,
                    'brand' => $brand,
                    'name' => "{$brand} {$model}",
                ]);

            $this->addImages($product);
        }
    }

    private function addImages(Product $product): void
    {
        ProductImage::factory()->primary()->for($product)->create();
        ProductImage::factory(fake()->numberBetween(0, 2))->for($product)->create();
    }
}
