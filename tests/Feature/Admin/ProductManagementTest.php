<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();
        $this->category = Category::factory()->create();
    }

    /**
     * GD isn't installed locally, so build fake images by MIME type instead of with fake()->image().
     */
    private function image(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'image/jpeg');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return [
            'category_id' => $this->category->id,
            'name' => 'Nike Air Jordan 1',
            'description' => 'Light wash, 90s cut.',
            'price' => '45.50',
            'condition' => ProductCondition::Good->value,
            'brand' => 'Nike',
            'size' => '8.5',
            'color' => 'Red/White/Black',
            'stock' => 1,
            'status' => ProductStatus::Available->value,
            ...$overrides,
        ];
    }

    private function productWithImages(int $count = 2): Product
    {
        $product = Product::factory()->for($this->category)->create();

        for ($i = 0; $i < $count; $i++) {
            $path = UploadedFile::fake()->create("existing-{$i}.jpg", 10, 'image/jpeg')->store("products/{$product->id}", 'public');
            $product->images()->create(['image_path' => $path, 'is_primary' => $i === 0]);
        }

        return $product;
    }

    // --- Access -----------------------------------------------------------

    public function test_customers_cannot_access_product_management(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($customer)->get(route('admin.products.index'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.products.store'), $this->validData())->assertForbidden();
        $this->actingAs($customer)->delete(route('admin.products.destroy', $product))->assertForbidden();

        $this->assertDatabaseCount('products', 1);
    }

    // --- List -------------------------------------------------------------

    public function test_index_lists_products_and_supports_search_and_filters(): void
    {
        $other = Category::factory()->create();
        // Names deliberately omit the brand so brand search is tested on its own.
        Product::factory()->for($this->category)->create(['name' => 'Dunk Low Panda', 'brand' => 'Nike', 'size' => 8.5, 'color' => 'Black/White']);
        Product::factory()->for($this->category)->hidden()->create(['name' => 'Air Max 90', 'brand' => 'Nike']);
        Product::factory()->for($other)->create(['name' => 'Samba OG', 'brand' => 'Adidas']);

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee(['Dunk Low Panda', 'Air Max 90', 'Samba OG', 'UK 8.5 · Black/White']);

        // Search matches brand...
        $this->actingAs($this->admin)->get(route('admin.products.index', ['search' => 'nike']))
            ->assertSee(['Dunk Low Panda', 'Air Max 90'])
            ->assertDontSee('Samba OG');

        // ...and name.
        $this->actingAs($this->admin)->get(route('admin.products.index', ['search' => 'samba']))
            ->assertSee('Samba OG')
            ->assertDontSee('Dunk Low Panda');

        $this->actingAs($this->admin)->get(route('admin.products.index', ['brand' => 'Adidas']))
            ->assertSee('Samba OG')
            ->assertDontSee('Dunk Low Panda');

        $this->actingAs($this->admin)->get(route('admin.products.index', ['category' => $other->id]))
            ->assertSee('Samba OG')
            ->assertDontSee('Dunk Low Panda');

        $this->actingAs($this->admin)->get(route('admin.products.index', ['status' => 'hidden']))
            ->assertSee('Air Max 90')
            ->assertDontSee('Dunk Low Panda');
    }

    public function test_index_paginates(): void
    {
        Product::factory(20)->for($this->category)->create();

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->count() === 15 && $products->total() === 20);
    }

    // --- Create -----------------------------------------------------------

    public function test_admin_can_create_a_product_with_images(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.products.store'), $this->validData([
            'images' => [$this->image('front.jpg'), $this->image('back.jpg')],
        ]));

        $product = Product::sole();
        $response->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success');

        $this->assertSame('nike-air-jordan-1', $product->slug);
        $this->assertSame('Nike', $product->brand);
        $this->assertSame('8.5', $product->size);
        $this->assertSame('UK 8.5', $product->size_label);
        $this->assertSame('Red/White/Black', $product->color);
        $this->assertSame('45.50', $product->price);
        $this->assertSame(ProductCondition::Good, $product->condition);

        $images = $product->images()->orderBy('id')->get();
        $this->assertCount(2, $images);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);
        $images->each(fn (ProductImage $image) => Storage::disk('public')->assertExists($image->image_path));
        $this->assertStringStartsWith("products/{$product->id}/", $images[0]->image_path);
    }

    public function test_duplicate_names_get_unique_slugs(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->validData());
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->validData());

        $this->assertEquals(
            ['nike-air-jordan-1', 'nike-air-jordan-1-2'],
            Product::orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_create_validates_input(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.products.create'))
            ->post(route('admin.products.store'), [
                'category_id' => 999,
                'name' => '',
                'price' => '-1',
                'condition' => 'brand_new',
                'size' => 'M',
                'stock' => 'lots',
                'status' => 'archived',
                'images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            ])
            ->assertRedirect(route('admin.products.create'))
            ->assertSessionHasErrors(['category_id', 'name', 'brand', 'price', 'condition', 'size', 'stock', 'status', 'images.0']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_size_must_be_a_whole_or_half_uk_size(): void
    {
        foreach (['8.25', '0', '16'] as $invalid) {
            $this->actingAs($this->admin)
                ->post(route('admin.products.store'), $this->validData(['size' => $invalid]))
                ->assertSessionHasErrors('size');
        }

        foreach (['3', '8.5', '12'] as $valid) {
            $this->actingAs($this->admin)
                ->post(route('admin.products.store'), $this->validData(['size' => $valid]))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('products', 3);
    }

    public function test_available_products_need_stock(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), $this->validData(['stock' => 0]))
            ->assertSessionHasErrors('stock');

        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), $this->validData(['stock' => 0, 'status' => 'sold']))
            ->assertSessionHasNoErrors();
    }

    public function test_image_uploads_are_limited_in_size_and_count(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), $this->validData([
                'images' => [UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg')],
            ]))
            ->assertSessionHasErrors('images.0');

        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), $this->validData([
                'images' => array_map(fn ($i) => $this->image("p{$i}.jpg"), range(1, 11)),
            ]))
            ->assertSessionHasErrors('images');

        $this->assertDatabaseCount('products', 0);
    }

    // --- Update -----------------------------------------------------------

    public function test_admin_can_update_product_fields_and_slug_follows_the_name(): void
    {
        $product = Product::factory()->for($this->category)->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->validData(['name' => 'Adidas Samba OG', 'price' => '19.99', 'status' => 'hidden']))
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertSame('Adidas Samba OG', $product->name);
        $this->assertSame('adidas-samba-og', $product->slug);
        $this->assertSame('19.99', $product->price);
        $this->assertSame(ProductStatus::Hidden, $product->status);
    }

    public function test_slug_is_kept_when_the_name_does_not_change(): void
    {
        $product = Product::factory()->for($this->category)->create(['name' => 'Nike Air Jordan 1', 'slug' => 'custom-slug']);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->validData(['price' => '10']));

        $this->assertSame('custom-slug', $product->fresh()->slug);
    }

    public function test_admin_can_change_the_primary_image(): void
    {
        $product = $this->productWithImages(2);
        [$first, $second] = $product->images()->orderBy('id')->get();

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->validData(['primary_image_id' => $second->id]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_removing_images_deletes_files_and_reassigns_primary(): void
    {
        $product = $this->productWithImages(3);
        [$primary, $second, $third] = $product->images()->orderBy('id')->get();

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->validData([
                'primary_image_id' => $primary->id,
                'remove_images' => [$primary->id, $third->id],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($primary);
        $this->assertModelMissing($third);
        Storage::disk('public')->assertMissing([$primary->image_path, $third->image_path]);
        Storage::disk('public')->assertExists($second->image_path);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_admin_can_add_images_to_an_existing_product(): void
    {
        $product = $this->productWithImages(1);
        $original = $product->images()->sole();

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->validData(['images' => [$this->image('new.jpg')]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $product->images()->count());
        $this->assertTrue($original->fresh()->is_primary, 'Adding images keeps the existing primary.');
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_image_limit_counts_existing_images(): void
    {
        $product = $this->productWithImages(9);

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->validData(['images' => [$this->image('a.jpg'), $this->image('b.jpg')]]))
            ->assertSessionHasErrors('images');

        // Removing one image frees a slot.
        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->validData([
                'remove_images' => [$product->images()->value('id')],
                'images' => [$this->image('a.jpg'), $this->image('b.jpg')],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(10, $product->images()->count());
    }

    public function test_cannot_touch_images_of_another_product(): void
    {
        $product = $this->productWithImages(1);
        $otherImage = $this->productWithImages(1)->images()->sole();

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->validData([
                'primary_image_id' => $otherImage->id,
                'remove_images' => [$otherImage->id],
            ]))
            ->assertSessionHasErrors(['primary_image_id', 'remove_images.0']);

        $this->assertModelExists($otherImage);
        Storage::disk('public')->assertExists($otherImage->image_path);
    }

    // --- Delete -----------------------------------------------------------

    public function test_admin_can_delete_a_product_and_its_image_files(): void
    {
        $product = $this->productWithImages(2);
        $paths = $product->images()->pluck('image_path')->all();

        $this->actingAs($this->admin)
            ->from(route('admin.products.index'))
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($product);
        $this->assertDatabaseCount('product_images', 0);
        Storage::disk('public')->assertMissing($paths);
        Storage::disk('public')->assertDirectoryEmpty('products');
    }

    public function test_cannot_delete_a_product_that_is_in_an_order(): void
    {
        $product = $this->productWithImages(1);
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->for($product)->create();

        $this->actingAs($this->admin)
            ->from(route('admin.products.index'))
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('error', "\"{$product->name}\" can't be deleted because it appears in 1 order. Set its status to Hidden instead.");

        $this->assertModelExists($product);
        Storage::disk('public')->assertExists($product->images()->sole()->image_path);

        $this->actingAs($this->admin)
            ->get(route('admin.products.index'))
            ->assertSee('appears in 1 order', false);
    }
}
