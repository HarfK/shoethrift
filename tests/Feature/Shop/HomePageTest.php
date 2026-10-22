<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_hero_and_newest_available_shoes_only(): void
    {
        Product::factory()->create(['name' => 'Older Pair', 'created_at' => now()->subDays(3)]);
        Product::factory()->create(['name' => 'Newest Pair', 'created_at' => now()]);
        Product::factory()->sold()->create(['name' => 'Sold Pair']);
        Product::factory()->hidden()->create(['name' => 'Hidden Pair']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Discover Premium Thrift Shoes in Malaysia')
            ->assertSeeInOrder(['Newly added', 'Newest Pair', 'Older Pair'])
            ->assertDontSee('Sold Pair')
            ->assertDontSee('Hidden Pair');
    }

    public function test_newly_added_is_limited_to_eight(): void
    {
        Product::factory(10)->create();

        $this->get('/')->assertViewHas('newArrivals', fn ($products) => $products->count() === 8);
    }

    public function test_shop_by_category_links_with_available_counts(): void
    {
        $sneakers = Category::factory()->create(['name' => 'Sneakers', 'slug' => 'sneakers']);
        Product::factory(2)->for($sneakers)->create();
        Product::factory()->for($sneakers)->sold()->create();

        $this->get('/')
            ->assertSee(route('shop.index', ['category' => 'sneakers']), false)
            ->assertSeeInOrder(['Sneakers', '2 pairs']);
    }

    public function test_header_account_menu_depends_on_who_is_logged_in(): void
    {
        $this->get('/')->assertSee(['Log in', 'Register'])->assertDontSee('Log out');

        $customer = User::factory()->create(['name' => 'Aisyah Rahman']);
        $this->actingAs($customer)->get('/')
            ->assertSee(['Aisyah Rahman', 'Log out'])
            ->assertDontSee('Admin dashboard');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/')->assertSee('Admin dashboard');
    }

    public function test_footer_lists_categories(): void
    {
        Category::factory()->create(['name' => 'Sandals', 'slug' => 'sandals']);

        $this->get('/')->assertSee(route('shop.index', ['category' => 'sandals']), false);
    }
}
