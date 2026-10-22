<?php

namespace Tests\Feature\Shop;

use App\Enums\ProductCondition;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopPageTest extends TestCase
{
    use RefreshDatabase;

    private Category $sneakers;

    private Category $boots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sneakers = Category::factory()->create(['name' => 'Sneakers', 'slug' => 'sneakers']);
        $this->boots = Category::factory()->create(['name' => 'Boots', 'slug' => 'boots']);

        // A small, fully specified catalogue so every filter has a known answer.
        $this->shoe('Nike Dunk Low', 'Nike', $this->sneakers, size: 8.5, condition: ProductCondition::LikeNew, price: 300, daysAgo: 1);
        $this->shoe('Nike Air Force 1', 'Nike', $this->sneakers, size: 9, condition: ProductCondition::Good, price: 150, daysAgo: 2);
        $this->shoe('Adidas Samba OG', 'Adidas', $this->sneakers, size: 8.5, condition: ProductCondition::Fair, price: 90, daysAgo: 3);
        $this->shoe('Dr. Martens 1460 Boot', 'Dr. Martens', $this->boots, size: 10, condition: ProductCondition::Good, price: 220, daysAgo: 4);
    }

    private function shoe(string $name, string $brand, Category $category, float $size, ProductCondition $condition, int $price, int $daysAgo): Product
    {
        return Product::factory()->for($category)->create([
            'name' => $name, 'brand' => $brand, 'size' => $size, 'condition' => $condition,
            'price' => $price, 'created_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function namesFor(array $query = []): array
    {
        $response = $this->get(route('shop.index', $query))->assertOk();

        return $response->viewData('products')->pluck('name')->all();
    }

    public function test_only_available_products_are_listed(): void
    {
        Product::factory()->for($this->sneakers)->sold()->create(['name' => 'Sold Pair']);
        Product::factory()->for($this->sneakers)->hidden()->create(['name' => 'Hidden Pair']);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee(['Nike Dunk Low', 'Showing 1–4 of 4 shoes'])
            ->assertDontSee('Sold Pair')
            ->assertDontSee('Hidden Pair');
    }

    public function test_search_matches_name_or_brand(): void
    {
        $this->assertEqualsCanonicalizing(['Nike Dunk Low', 'Nike Air Force 1'], $this->namesFor(['search' => 'nike']));
        $this->assertSame(['Adidas Samba OG'], $this->namesFor(['search' => 'samba']));

        $this->get(route('shop.index', ['search' => 'samba']))->assertSee('value="samba"', false);
    }

    public function test_category_filter_uses_the_slug(): void
    {
        $this->assertSame(['Dr. Martens 1460 Boot'], $this->namesFor(['category' => 'boots']));

        $this->get(route('shop.index', ['category' => 'boots']))->assertSee('<h1', false)->assertSee('Boots');
    }

    public function test_brand_filter(): void
    {
        $this->assertEqualsCanonicalizing(['Nike Dunk Low', 'Nike Air Force 1'], $this->namesFor(['brand' => 'Nike']));
    }

    public function test_size_filter_matches_exact_uk_size_including_half_sizes(): void
    {
        $this->assertEqualsCanonicalizing(['Nike Dunk Low', 'Adidas Samba OG'], $this->namesFor(['size' => '8.5']));
        $this->assertSame(['Nike Air Force 1'], $this->namesFor(['size' => '9']));
        $this->assertSame([], $this->namesFor(['size' => '11']));
    }

    public function test_condition_filter_accepts_several_conditions(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Nike Dunk Low', 'Adidas Samba OG'],
            $this->namesFor(['condition' => ['like_new', 'fair']]),
        );
    }

    public function test_price_range_filter_and_swapped_bounds(): void
    {
        $this->assertEqualsCanonicalizing(['Nike Air Force 1', 'Dr. Martens 1460 Boot'], $this->namesFor(['min_price' => 100, 'max_price' => 250]));
        // min > max is treated as the same range.
        $this->assertEqualsCanonicalizing(['Nike Air Force 1', 'Dr. Martens 1460 Boot'], $this->namesFor(['min_price' => 250, 'max_price' => 100]));
        $this->assertSame(['Adidas Samba OG'], $this->namesFor(['max_price' => 90]));
    }

    public function test_filters_combine(): void
    {
        $this->assertSame(['Nike Dunk Low'], $this->namesFor(['category' => 'sneakers', 'brand' => 'Nike', 'size' => '8.5']));
    }

    public function test_sorting(): void
    {
        $this->assertSame(
            ['Nike Dunk Low', 'Nike Air Force 1', 'Adidas Samba OG', 'Dr. Martens 1460 Boot'],
            $this->namesFor(),
            'Default sort is newest first.',
        );
        $this->assertSame(
            ['Adidas Samba OG', 'Nike Air Force 1', 'Dr. Martens 1460 Boot', 'Nike Dunk Low'],
            $this->namesFor(['sort' => 'price_asc']),
        );
        $this->assertSame(
            ['Nike Dunk Low', 'Dr. Martens 1460 Boot', 'Nike Air Force 1', 'Adidas Samba OG'],
            $this->namesFor(['sort' => 'price_desc']),
        );
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        $this->assertCount(4, $this->namesFor([
            'category' => 'does-not-exist',
            'size' => 'huge',
            'condition' => ['brand_new'],
            'min_price' => 'cheap',
            'sort' => 'random',
        ]));

        // Array where a string is expected must not crash.
        $this->get('/shop?search[]=x&brand[]=y&sort[]=z')->assertOk();
    }

    public function test_pagination_is_twelve_per_page_and_keeps_filters(): void
    {
        Product::factory(10)->for($this->sneakers)->create(['brand' => 'Nike']);

        $this->get(route('shop.index', ['brand' => 'Nike']))
            ->assertViewHas('products', fn ($products) => $products->count() === 12 && $products->total() === 12);

        $response = $this->get(route('shop.index'));
        $response->assertViewHas('products', fn ($products) => $products->count() === 12 && $products->total() === 14);

        $this->get(route('shop.index', ['brand' => 'Nike', 'page' => 1]))->assertDontSee('page=2', false);
        $this->get(route('shop.index', ['page' => 2]))
            ->assertViewHas('products', fn ($products) => $products->count() === 2);
    }

    public function test_filter_options_only_include_values_in_stock(): void
    {
        Product::factory()->for(Category::factory()->create(['name' => 'Sandals']))->sold()->create(['brand' => 'Crocs', 'size' => 4]);

        $this->get(route('shop.index'))
            ->assertViewHas('brands', fn ($brands) => $brands->all() === ['Adidas', 'Dr. Martens', 'Nike'])
            ->assertViewHas('sizes', fn ($sizes) => $sizes->all() === ['8.5', '9', '10'])
            ->assertViewHas('categories', fn ($categories) => $categories->pluck('name')->all() === ['Boots', 'Sneakers']);
    }

    public function test_empty_results_show_a_helpful_message(): void
    {
        $this->get(route('shop.index', ['search' => 'zzz']))
            ->assertSee('No shoes match your filters.')
            ->assertSee('Clear filters');
    }
}
