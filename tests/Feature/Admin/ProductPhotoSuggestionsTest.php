<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductPhotoSuggestionsTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return [
            'category_id' => $this->category->id,
            'name' => 'Adidas Samba OG',
            'brand' => 'Adidas',
            'price' => '120.00',
            'condition' => ProductCondition::Good->value,
            'size' => '8',
            'color' => 'Black/White',
            'stock' => 1,
            'status' => ProductStatus::Available->value,
            ...$overrides,
        ];
    }

    private function upload(string $name = 'real.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'image/jpeg');
    }

    /**
     * @param  list<string>  $paths  files are created on the fake disk; the first is primary
     * @param  array<string, mixed>  $attributes
     */
    private function productWith(array $paths, array $attributes = []): Product
    {
        $product = Product::factory()->for($this->category)->create(['name' => 'Adidas Samba OG', 'brand' => 'Adidas', 'color' => 'Black/White', ...$attributes]);
        foreach ($paths as $i => $path) {
            Storage::disk('public')->put($path, 'photo');
            $product->images()->create(['image_path' => $path, 'is_primary' => $i === 0]);
        }

        return $product;
    }

    /**
     * @return list<string>
     */
    private function library(int $count = 2): array
    {
        return array_map(fn ($n) => "products/library/adidas-samba-og--black-white-{$n}.jpg", range(1, $count));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function names(array $query): TestResponse
    {
        return $this->actingAs($this->admin)->getJson(route('admin.products.name-suggestions', $query));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function photos(array $query): TestResponse
    {
        return $this->actingAs($this->admin)->getJson(route('admin.products.photo-suggestions', $query));
    }

    // --- Name autocomplete --------------------------------------------------

    public function test_name_suggestions_are_distinct_and_match_any_part_in_any_case(): void
    {
        $this->productWith([], ['name' => 'Nike Dunk Low', 'brand' => 'Nike']);
        $newest = $this->productWith([], ['name' => 'nike dunk low', 'brand' => 'Nike']);
        $this->productWith([], ['name' => 'Adidas Samba OG']);

        $this->names(['q' => 'DUNK'])->assertOk()->assertExactJson(['suggestions' => [
            ['name' => $newest->name, 'brand' => 'Nike', 'category_id' => $this->category->id],
        ]]);
    }

    public function test_name_suggestions_stop_at_eight(): void
    {
        foreach (range(1, 10) as $n) {
            $this->productWith([], ['name' => "Model {$n}"]);
        }

        $this->names(['q' => 'model'])->assertJsonCount(8, 'suggestions');
    }

    public function test_name_suggestions_treat_wildcards_as_text_and_ignore_bad_input(): void
    {
        $this->productWith([], ['name' => 'Nike Dunk Low']);

        $this->names(['q' => '%'])->assertExactJson(['suggestions' => []]);
        $this->names(['q' => '   '])->assertExactJson(['suggestions' => []]);
        $this->actingAs($this->admin)->getJson(route('admin.products.name-suggestions').'?q[]=x')->assertOk()->assertExactJson(['suggestions' => []]);
    }

    public function test_lookups_are_admin_only(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->getJson(route('admin.products.name-suggestions', ['q' => 'a']))->assertForbidden();
        $this->actingAs($customer)->getJson(route('admin.products.photo-suggestions', ['name' => 'a', 'color' => 'b']))->assertForbidden();
    }

    // --- Photo lookup -------------------------------------------------------

    public function test_photo_lookup_uses_the_import_slug_rule_and_keeps_the_primary_first(): void
    {
        $this->productWith($this->library());

        $this->photos(['name' => 'ADIDAS samba og', 'color' => 'black white'])->assertOk()->assertExactJson([
            'found' => true,
            'photos' => [
                asset('storage/products/library/adidas-samba-og--black-white-1.jpg'),
                asset('storage/products/library/adidas-samba-og--black-white-2.jpg'),
            ],
            'more' => 0,
        ]);
    }

    public function test_photo_lookup_shows_four_thumbnails_and_counts_the_rest(): void
    {
        $this->productWith($this->library(6));

        $this->photos(['name' => 'Adidas Samba OG', 'color' => 'Black/White'])
            ->assertJsonCount(4, 'photos')->assertJsonPath('more', 2);
    }

    public function test_photo_lookup_finds_nothing_without_a_full_match(): void
    {
        $this->productWith($this->library());
        $none = ['found' => false, 'photos' => [], 'more' => 0];

        $this->photos(['name' => 'Adidas Samba OG', 'color' => 'Pink'])->assertExactJson($none);
        $this->photos(['name' => 'Other Model', 'color' => 'Black/White'])->assertExactJson($none);
        $this->photos(['name' => 'Adidas Samba OG', 'color' => ''])->assertExactJson($none);
    }

    public function test_photo_lookup_never_offers_an_own_upload_a_demo_copy_or_a_missing_file(): void
    {
        $this->productWith(['products/1/own-upload.jpg']);
        $this->productWith(['products/2/attached-demo.jpg']);
        $missing = $this->productWith([]);
        $missing->images()->create(['image_path' => 'products/library/gone.jpg', 'is_primary' => true]);

        $this->photos(['name' => 'Adidas Samba OG', 'color' => 'Black/White'])->assertJsonPath('found', false);
    }

    public function test_a_partial_colour_is_not_a_photo_match(): void
    {
        $this->productWith($this->library());

        $this->photos(['name' => 'Adidas Samba OG', 'color' => 'Black'])->assertJsonPath('found', false);
        $this->photos(['name' => 'Adidas Samba OG', 'color' => 'black-whi'])->assertJsonPath('found', false);
        $this->photos(['name' => 'Adidas Samba OG', 'color' => 'black-white'])->assertJsonPath('found', true);
    }

    // --- Colour suggestions -------------------------------------------------

    /**
     * @param  list<string|null>  $colors
     */
    private function sambaIn(array $colors): void
    {
        foreach ($colors as $color) {
            $this->productWith([], ['color' => $color]);
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    private function colors(array $query): TestResponse
    {
        return $this->actingAs($this->admin)->getJson(route('admin.products.color-suggestions', $query));
    }

    public function test_colours_are_listed_per_model_without_duplicates_or_blanks(): void
    {
        $this->sambaIn(['White', 'Black/White', 'black white', 'White', null, '']);
        $this->productWith([], ['name' => 'Nike Dunk Low', 'color' => 'Green']);

        $colors = $this->colors(['name' => 'adidas samba og'])->assertOk()->json('colors');

        $this->assertCount(2, $colors);
        $this->assertContains('White', $colors);
        $this->assertNotContains('Green', $colors);
    }

    public function test_colours_narrow_with_matches_at_the_start_first(): void
    {
        $this->sambaIn(['Black/White', 'White', 'White/Green', 'Beige']);

        $this->colors(['name' => 'Adidas Samba OG', 'q' => 'WHI'])
            ->assertExactJson(['colors' => ['White', 'White/Green', 'Black/White']]);
    }

    public function test_colour_narrowing_ignores_slashes_and_dashes(): void
    {
        $this->sambaIn(['Black/White', 'Red-Gum', 'White']);
        $name = 'Adidas Samba OG';

        $this->colors(['name' => $name, 'q' => 'black white'])->assertExactJson(['colors' => ['Black/White']]);
        $this->colors(['name' => $name, 'q' => 'black-white'])->assertExactJson(['colors' => ['Black/White']]);
        $this->colors(['name' => $name, 'q' => 'blackwhite'])->assertExactJson(['colors' => ['Black/White']]);
        $this->colors(['name' => $name, 'q' => 'red/gum'])->assertExactJson(['colors' => ['Red-Gum']]);
    }

    public function test_colours_are_empty_for_an_unknown_model_or_a_colour_that_matches_nothing(): void
    {
        $this->sambaIn(['White']);

        $this->colors(['name' => 'Unknown Model'])->assertOk()->assertExactJson(['colors' => []]);
        $this->colors(['name' => ''])->assertExactJson(['colors' => []]);
        $this->colors(['name' => 'Adidas Samba OG', 'q' => 'Teal'])->assertExactJson(['colors' => []]);
        $this->actingAs($this->admin)->getJson(route('admin.products.color-suggestions').'?name[]=x')->assertOk()->assertExactJson(['colors' => []]);
    }

    public function test_colour_list_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('admin.products.color-suggestions', ['name' => 'a']))->assertForbidden();
    }

    public function test_form_has_the_colour_suggestions_and_a_free_new_colour_saves(): void
    {
        $this->actingAs($this->admin)->get(route('admin.products.create'))->assertOk()
            ->assertSee('list="color-options"', false)
            ->assertSee('id="color-chips"', false)
            ->assertSee('New colour. Upload photos for it.');

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->formData(['color' => 'Teal/Orange']))->assertSessionHasNoErrors();

        $this->assertSame('Teal/Orange', Product::latest('id')->first()->color);
    }

    // --- Create -------------------------------------------------------------

    public function test_use_these_photos_points_a_new_product_at_the_same_files(): void
    {
        $this->productWith($this->library());
        $filesBefore = Storage::disk('public')->allFiles();

        $response = $this->actingAs($this->admin)->post(route('admin.products.store'), $this->formData(['use_existing_photos' => '1']));

        $product = Product::latest('id')->first();
        $response->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Using existing photos. Upload your own to replace them.'));
        $images = $product->images()->orderBy('id')->get();
        $this->assertSame($this->library(), $images->pluck('image_path')->all());
        $this->assertSame([true, false], $images->pluck('is_primary')->all());
        $this->assertSame($filesBefore, Storage::disk('public')->allFiles(), 'No file is copied.');
    }

    public function test_uploading_wins_over_use_these_photos(): void
    {
        $this->productWith($this->library());

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->formData([
            'use_existing_photos' => '1',
            'images' => [$this->upload('a.jpg'), $this->upload('b.jpg')],
        ]))->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => ! str_contains($message, 'existing photos'));

        $product = Product::latest('id')->first();
        $this->assertSame(2, $product->images()->count());
        $this->assertFalse($product->usesStockPhotos());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_a_product_with_neither_has_no_photo(): void
    {
        $this->productWith($this->library());

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->formData(['use_existing_photos' => '0']))->assertSessionHasNoErrors();

        $this->assertSame(0, Product::latest('id')->first()->images()->count());
    }

    public function test_use_these_photos_says_so_when_the_set_is_gone(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->formData(['use_existing_photos' => '1']))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'No existing photos were found'));

        $this->assertSame(0, Product::latest('id')->first()->images()->count());
    }

    // --- Edit ---------------------------------------------------------------

    public function test_edit_can_use_these_photos_when_the_product_has_none(): void
    {
        $this->productWith($this->library());
        $product = $this->productWith([]);

        $this->actingAs($this->admin)->get(route('admin.products.edit', $product))->assertSee('id="existing-photos"', false);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->formData(['use_existing_photos' => '1']))->assertSessionHasNoErrors();

        $this->assertSame($this->library(), $product->images()->orderBy('id')->pluck('image_path')->all());
    }

    public function test_use_these_photos_is_ignored_when_the_product_already_has_photos(): void
    {
        $this->productWith($this->library());
        $product = $this->productWith(['products/99/own.jpg']);

        $this->actingAs($this->admin)->get(route('admin.products.edit', $product))->assertDontSee('id="existing-photos"', false);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->formData(['use_existing_photos' => '1']))->assertSessionHasNoErrors();

        $this->assertSame(['products/99/own.jpg'], $product->images()->pluck('image_path')->all());
    }

    public function test_uploads_replace_stock_photos_and_keep_files_others_use(): void
    {
        $other = $this->productWith($this->library());
        $product = $this->productWith($this->library());
        $primaryId = $product->images()->where('is_primary', true)->value('id');

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->formData([
            'primary_image_id' => $primaryId,
            'images' => [$this->upload()],
        ]))->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertFalse($product->usesStockPhotos());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $this->assertSame(2, $other->images()->count());
        foreach ($this->library() as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_uploads_replace_demo_copies_but_keep_earlier_real_uploads(): void
    {
        $product = $this->productWith(['products/5/attached-demo.jpg', 'products/5/real.jpg']);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->formData(['images' => [$this->upload()]]))->assertSessionHasNoErrors();

        $paths = $product->images()->pluck('image_path');
        $this->assertNotContains('products/5/attached-demo.jpg', $paths);
        $this->assertContains('products/5/real.jpg', $paths);
        $this->assertCount(2, $paths);
        Storage::disk('public')->assertMissing('products/5/attached-demo.jpg');
    }

    public function test_stock_photos_being_replaced_do_not_use_up_the_image_limit(): void
    {
        $product = $this->productWith($this->library(10));

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->formData(['images' => [$this->upload()]]))->assertSessionHasNoErrors();

        $this->assertSame(1, $product->images()->count());
    }

    public function test_edit_form_warns_only_for_stock_photos(): void
    {
        $text = 'This product uses stock photos. Upload real photos of this pair.';

        $this->actingAs($this->admin)->get(route('admin.products.edit', $this->productWith($this->library())))->assertSee($text);
        $this->actingAs($this->admin)->get(route('admin.products.edit', $this->productWith(['products/6/attached-demo.jpg'])))->assertSee($text);
        $this->actingAs($this->admin)->get(route('admin.products.edit', $this->productWith(['products/7/own.jpg'])))->assertDontSee($text);
    }

    // --- List badge and filter ----------------------------------------------

    public function test_badge_appears_only_on_stock_photo_products(): void
    {
        $this->productWith($this->library(), ['name' => 'Stock Pair']);

        $this->actingAs($this->admin)->get(route('admin.products.index'))->assertOk()->assertSee('data-stock-photo', false)->assertSee('Stock photo');

        Product::query()->delete();
        $this->productWith(['products/9/attached-demo.jpg'], ['name' => 'Demo Pair']);

        $this->actingAs($this->admin)->get(route('admin.products.index'))->assertSee('data-stock-photo', false);

        Product::query()->delete();
        $this->productWith(['products/8/own.jpg'], ['name' => 'Own Pair']);

        $this->actingAs($this->admin)->get(route('admin.products.index'))->assertDontSee('data-stock-photo', false);
    }

    public function test_photos_filter_separates_stock_own_and_no_photo(): void
    {
        $this->productWith($this->library(), ['name' => 'Stock Pair']);
        $this->productWith(['products/4/attached-demo.jpg'], ['name' => 'Demo Pair']);
        $this->productWith(['products/4/attached-mixed.jpg', 'products/4/real.jpg'], ['name' => 'Mixed Pair']);
        $this->productWith(['products/8/own.jpg'], ['name' => 'Own Pair']);
        $this->productWith([], ['name' => 'No Photo Pair']);
        $names = fn (array $query) => $this->actingAs($this->admin)->get(route('admin.products.index', $query))
            ->viewData('products')->pluck('name')->sort()->values()->all();

        $all = ['Demo Pair', 'Mixed Pair', 'No Photo Pair', 'Own Pair', 'Stock Pair'];

        $this->assertSame(['Demo Pair', 'Mixed Pair', 'Stock Pair'], $names(['photos' => 'stock']));
        $this->assertSame(['Own Pair'], $names(['photos' => 'own']));
        $this->assertSame($all, $names([]));
        $this->assertSame($all, $names(['photos' => 'nonsense']));
    }

    public function test_photos_filter_works_with_the_other_filters_sorting_and_pagination(): void
    {
        foreach (range(1, 16) as $n) {
            $this->productWith(["products/library/x-{$n}.jpg"], ['name' => sprintf('Stock %02d', $n), 'brand' => 'Nike']);
        }
        $this->productWith(['products/library/other.jpg'], ['name' => 'Stock Puma', 'brand' => 'Puma']);
        $this->productWith(['products/8/own.jpg'], ['name' => 'Own Nike', 'brand' => 'Nike']);

        $page = $this->actingAs($this->admin)->get(route('admin.products.index', [
            'photos' => 'stock', 'brand' => 'Nike', 'category' => $this->category->id, 'search' => 'Stock', 'sort' => 'category', 'direction' => 'asc',
        ]))->assertOk()->viewData('products');

        $this->assertSame(16, $page->total());
        $this->assertStringContainsString('photos=stock', $page->nextPageUrl());
    }

    public function test_the_shop_never_shows_the_stock_photo_badge(): void
    {
        $product = $this->productWith($this->library(), ['status' => ProductStatus::Available, 'stock' => 1]);

        $this->get(route('shop.index'))->assertOk()->assertDontSee('Stock photo');
        $this->get(route('shop.show', $product->slug))->assertOk()->assertDontSee('Stock photo');
    }
}
