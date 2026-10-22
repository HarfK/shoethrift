<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_customers_cannot_manage_categories(): void
    {
        $customer = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($customer)->get(route('admin.categories.index'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.categories.store'), ['name' => 'Boots'])->assertForbidden();
        $this->actingAs($customer)->delete(route('admin.categories.destroy', $category))->assertForbidden();

        $this->assertModelExists($category);
    }

    public function test_index_lists_categories_with_product_counts(): void
    {
        $sneakers = Category::factory()->create(['name' => 'Sneakers']);
        Category::factory()->create(['name' => 'Sandals']);
        Product::factory(3)->for($sneakers)->create();

        $this->actingAs($this->admin)->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSeeInOrder(['Sandals', '0', 'Sneakers', '3']);
    }

    public function test_admin_can_create_a_category_with_a_generated_slug(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), ['name' => 'Running Shoes', 'description' => 'For the track.'])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $category = Category::sole();
        $this->assertSame('running-shoes', $category->slug);
        $this->assertSame('For the track.', $category->description);
    }

    public function test_category_names_must_be_unique_and_slugs_stay_unique(): void
    {
        Category::factory()->create(['name' => 'Boots', 'slug' => 'boots']);

        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), ['name' => 'Boots'])
            ->assertSessionHasErrors(['name' => 'A category with this name already exists.']);

        // A different name that slugifies the same still gets a unique slug.
        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), ['name' => 'Boots!'])
            ->assertSessionHasNoErrors();

        $this->assertSame('boots-2', Category::where('name', 'Boots!')->value('slug'));
    }

    public function test_create_requires_a_name(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_renaming_a_category_regenerates_its_slug(): void
    {
        $category = Category::factory()->create(['name' => 'Trainers', 'slug' => 'trainers']);

        $this->actingAs($this->admin)
            ->put(route('admin.categories.update', $category), ['name' => 'Running', 'description' => 'Updated'])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $category->refresh();
        $this->assertSame('Running', $category->name);
        $this->assertSame('running', $category->slug);
        $this->assertSame('Updated', $category->description);
    }

    public function test_saving_without_renaming_keeps_name_unique_check_happy_and_slug_unchanged(): void
    {
        $category = Category::factory()->create(['name' => 'Boots', 'slug' => 'custom-boots']);

        $this->actingAs($this->admin)
            ->put(route('admin.categories.update', $category), ['name' => 'Boots', 'description' => 'New text'])
            ->assertSessionHasNoErrors();

        $this->assertSame('custom-boots', $category->fresh()->slug);
    }

    public function test_admin_can_delete_an_empty_category(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.categories.index'))
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($category);
    }

    public function test_cannot_delete_a_category_that_has_products(): void
    {
        $category = Category::factory()->create(['name' => 'Sneakers']);
        Product::factory(2)->for($category)->create();
        Product::factory()->for($category)->hidden()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.categories.index'))
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('error', '"Sneakers" can\'t be deleted because it still has 3 products. Move them to another category or delete them first.');

        $this->assertModelExists($category);
    }
}
