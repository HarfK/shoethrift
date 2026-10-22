<?php

namespace Database\Factories;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->randomElement(['vintage', 'retro', 'classic', 'oversized', 'faded', 'cropped'])
            .' '.fake()->colorName()
            .' '.fake()->randomElement(['shirt', 'jacket', 'jeans', 'dress', 'sweater', 'boots', 'bag', 'scarf']));

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 5, 150),
            'condition' => fake()->randomElement(ProductCondition::cases()),
            'size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'One Size']),
            'stock' => 1,
            'status' => ProductStatus::Available,
        ];
    }

    /**
     * Indicate that the product has been sold.
     */
    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => 0,
            'status' => ProductStatus::Sold,
        ]);
    }

    /**
     * Indicate that the product is hidden from the storefront.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Hidden,
        ]);
    }
}
