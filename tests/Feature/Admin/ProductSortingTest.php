<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sortable admin products list (?sort=...&direction=asc|desc), with filters and pagination.
 */
class ProductSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $boots = Category::factory()->create(['name' => 'Boots']);
        $casual = Category::factory()->create(['name' => 'Casual']);
        $sandals = Category::factory()->create(['name' => 'Sandals']);

        // Each column has a different order, so a wrong sort can't pass by accident.
        $rows = [
            ['A Pair', $sandals, 'Vans', 120, ProductCondition::Fair, 5, ProductStatus::Hidden, '2026-10-03'],
            ['B Pair', $boots, 'Clarks', 300, ProductCondition::LikeNew, 1, ProductStatus::Sold, '2026-10-01'],
            ['C Pair', $casual, 'Nike', 80, ProductCondition::Good, 3, ProductStatus::Available, '2026-10-02'],
        ];
        foreach ($rows as [$name, $category, $brand, $price, $condition, $stock, $status, $date]) {
            Product::factory()->for($category)->create([
                'name' => $name, 'brand' => $brand, 'price' => $price, 'condition' => $condition, 'stock' => $stock,
                'status' => $status, 'created_at' => $date.' 10:00:00',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function names(array $query = []): array
    {
        return $this->actingAs($this->admin)->get(route('admin.products.index', $query))
            ->assertOk()->viewData('products')->pluck('name')->all();
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function columns(): array
    {
        return [
            'name' => ['name', ['A Pair', 'B Pair', 'C Pair']],
            'category (by name)' => ['category', ['B Pair', 'C Pair', 'A Pair']],          // Boots, Casual, Sandals
            'brand' => ['brand', ['B Pair', 'C Pair', 'A Pair']],                          // Clarks, Nike, Vans
            'price' => ['price', ['C Pair', 'A Pair', 'B Pair']],                          // 80, 120, 300
            'condition (Like new > Good > Fair)' => ['condition', ['B Pair', 'C Pair', 'A Pair']],
            'stock' => ['stock', ['B Pair', 'C Pair', 'A Pair']],                          // 1, 3, 5
            'status (Available, Hidden, Sold)' => ['status', ['C Pair', 'A Pair', 'B Pair']],
            'date added' => ['created_at', ['B Pair', 'C Pair', 'A Pair']],               // 1, 2, 3 Oct
        ];
    }

    /**
     * @param  list<string>  $ascending
     */
    #[DataProvider('columns')]
    public function test_each_column_sorts_both_ways(string $column, array $ascending): void
    {
        $this->assertSame($ascending, $this->names(['sort' => $column, 'direction' => 'asc']));
        $this->assertSame(array_reverse($ascending), $this->names(['sort' => $column, 'direction' => 'desc']));
    }

    public function test_default_is_newest_first(): void
    {
        $this->assertSame(['A Pair', 'C Pair', 'B Pair'], $this->names());
    }

    public function test_an_invalid_sort_falls_back_to_the_default(): void
    {
        $default = ['A Pair', 'C Pair', 'B Pair'];
        $this->assertSame($default, $this->names(['sort' => 'password', 'direction' => 'asc']));
        $this->assertSame($default, $this->names(['sort' => 'id; drop table products', 'direction' => 'desc']));
        $this->assertSame($default, $this->names(['sort' => ['name'], 'direction' => 'asc']));
        // A valid column with a bad direction sorts ascending.
        $this->assertSame(['A Pair', 'B Pair', 'C Pair'], $this->names(['sort' => 'name', 'direction' => 'sideways']));
    }

    public function test_headers_toggle_and_show_the_active_arrow(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.index', ['sort' => 'price', 'direction' => 'asc', 'brand' => 'Nike']));

        $response->assertSee('data-sort-active="asc"', false)
            ->assertSee('aria-sort="ascending"', false)
            // Clicking the active column flips it; other columns start ascending; the filter is kept.
            ->assertSee(route('admin.products.index', ['brand' => 'Nike', 'sort' => 'price', 'direction' => 'desc']))
            ->assertSee(route('admin.products.index', ['brand' => 'Nike', 'sort' => 'name', 'direction' => 'asc']))
            ->assertSee('Date added');
    }

    public function test_filters_and_sort_work_together(): void
    {
        Product::factory()->for(Category::firstWhere('name', 'Casual'))->create(['name' => 'D Pair', 'brand' => 'Nike', 'price' => 50, 'size' => 9]);
        Product::factory()->for(Category::firstWhere('name', 'Casual'))->create(['name' => 'E Pair', 'brand' => 'Nike', 'price' => 500, 'size' => 9]);

        $this->assertSame(['E Pair', 'C Pair', 'D Pair'], $this->names(['brand' => 'Nike', 'sort' => 'price', 'direction' => 'desc']));
        $casual = Category::firstWhere('name', 'Casual')->id;
        $this->assertSame(['D Pair', 'C Pair', 'E Pair'], $this->names(['search' => 'Pair', 'category' => $casual, 'sort' => 'price', 'direction' => 'asc']));

        // Sorting by category (a join) still respects the filters and doesn't mix up column names.
        $this->assertSame(['C Pair', 'D Pair', 'E Pair'], $this->names(['search' => 'Pair', 'brand' => 'Nike', 'sort' => 'category', 'direction' => 'asc']));
        $this->assertSame(['A Pair'], $this->names(['status' => 'hidden', 'sort' => 'category', 'direction' => 'desc']));
    }

    public function test_the_filter_form_keeps_the_sort(): void
    {
        $this->actingAs($this->admin)->get(route('admin.products.index', ['sort' => 'stock', 'direction' => 'desc']))
            ->assertSee('<input type="hidden" name="sort" value="stock">', false)
            ->assertSee('<input type="hidden" name="direction" value="desc">', false);
    }

    public function test_pagination_keeps_the_sort(): void
    {
        foreach (range(1, 20) as $i) {
            Product::factory()->create(['name' => sprintf('Z%02d', $i), 'price' => 1000 + $i]);
        }

        $page = $this->actingAs($this->admin)->get(route('admin.products.index', ['sort' => 'price', 'direction' => 'desc', 'page' => 2]));
        $products = $page->viewData('products');

        $this->assertSame(23, $products->total());
        $this->assertSame(['Z05', 'Z04', 'Z03', 'Z02', 'Z01', 'B Pair', 'A Pair', 'C Pair'], $products->pluck('name')->all());
        $this->assertStringContainsString('sort=price', $products->url(1));
        $this->assertStringContainsString('direction=desc', $products->url(1));
        $page->assertSee('sort=price&amp;direction=desc&amp;page=1', false);
    }
}
