<?php

namespace Database\Factories;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\SoleCondition;
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
     * Realistic thrift stock: [brand, model, category name]. CategorySeeder creates these categories.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const CATALOG = [
        ['Nike', 'Air Jordan 1', 'Sneakers'],
        ['Nike', 'Air Force 1', 'Sneakers'],
        ['Nike', 'Dunk Low', 'Sneakers'],
        ['Nike', 'Pegasus 40', 'Running'],
        ['Nike', 'Blazer Mid 77', 'Casual'],
        ['Adidas', 'Ultraboost', 'Running'],
        ['Adidas', 'Superstar', 'Sneakers'],
        ['Adidas', 'Samba OG', 'Casual'],
        ['Adidas', 'Stan Smith', 'Casual'],
        ['Vans', 'Old Skool', 'Casual'],
        ['Vans', 'Sk8-Hi', 'Sneakers'],
        ['Converse', 'Chuck Taylor All Star', 'Casual'],
        ['Converse', 'Chuck 70', 'Casual'],
        ['New Balance', '327', 'Sneakers'],
        ['New Balance', '550', 'Sneakers'],
        ['New Balance', 'Fresh Foam 1080', 'Running'],
        ['Puma', 'RS-X', 'Sneakers'],
        ['Puma', 'Suede Classic', 'Casual'],
        ['Asics', 'Gel-Kayano 30', 'Running'],
        ['Asics', 'Gel-Lyte III', 'Sneakers'],
        ['Dr. Martens', '1460 Boot', 'Boots'],
        ['Timberland', '6-Inch Premium Boot', 'Boots'],
        ['Clarks', 'Desert Boot', 'Boots'],
        ['Dr. Martens', '1461 Oxford', 'Formal'],
        ['Clarks', 'Tilden Cap Oxford', 'Formal'],
        ['Birkenstock', 'Arizona', 'Sandals'],
        ['Crocs', 'Classic Clog', 'Sandals'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$brand, $model] = fake()->randomElement(self::CATALOG);

        return [
            'category_id' => Category::factory(),
            'brand' => $brand,
            'name' => "{$brand} {$model}",
            'slug' => fn (array $attributes) => Str::slug($attributes['name']).'-'.Str::lower(Str::random(6)),
            'description' => fake()->randomElement([
                'Pre-loved pair, cleaned and inspected.',
                'Light signs of wear on the sole; upper in great shape.',
                'Original laces and insoles. No box.',
                'Minor creasing on the toe box, otherwise clean.',
            ]),
            'price' => fake()->numberBetween(5, 45) * 10,
            'condition' => fake()->randomElement(ProductCondition::cases()),
            'size' => fake()->randomElement(range(3, 12, 0.5)),
            'color' => fake()->randomElement(['Black', 'White', 'Black/White', 'Red/White/Black', 'Navy', 'Grey', 'Beige', 'Brown', 'Green/White']),
            'material' => fake()->randomElement(['Leather', 'Leather', 'Suede', 'Canvas', 'Mesh / Knit', 'Synthetic']),
            // Roughly UK size + 18 cm, which is how long a UK insole runs.
            'insole_length_cm' => fn (array $attributes) => round((float) $attributes['size'] + 18 + fake()->randomFloat(1, -0.4, 0.4), 1),
            'sole_condition' => fake()->randomElement(SoleCondition::cases()),
            'box_included' => fake()->boolean(35),
            'wear_notes' => fake()->optional(0.7)->randomElement([
                'Light creasing on the toe box. Small scuff on the left heel.',
                'Minor yellowing on the midsole. No tears.',
                'Faint marks on the suede, cleaned. Laces replaced.',
                'Outsole worn at the heels, upper in great shape.',
            ]),
            'authenticity_notes' => fake()->optional(0.6)->randomElement([
                'Checked style code on the tongue label against the brand database.',
                'Stitching, insole print and size tag match a retail pair.',
                'Bought from an authorised store; original receipt seen.',
            ]),
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
