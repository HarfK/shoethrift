<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * products:attach-photos links photo files to products by model name + colour.
 */
class AttachProductPhotosTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'attach-photos-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/nike-dunk-low--green-white.jpg', 'dunk green');
        File::put($this->dir.'/nike-dunk-low.jpg', 'dunk any colour');
        File::put($this->dir.'/adidas-samba-og--red-white-black.jpg', 'samba');
        File::put($this->dir.'/notes.txt', 'ignored');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function pair(string $name, string $color, ProductStatus $status, string $placeholder = 'products/missing.jpg'): Product
    {
        $product = Product::factory()->create(['name' => $name, 'color' => $color, 'status' => $status]);
        $product->images()->create(['image_path' => $placeholder, 'is_primary' => true]);

        return $product;
    }

    private function attach(array $options = []): void
    {
        $this->artisan('products:attach-photos', ['dir' => $this->dir, ...$options])->assertSuccessful();
    }

    public function test_it_links_photos_by_model_and_colour_with_own_copies_for_unsold_pairs(): void
    {
        $soldA = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Sold);
        $soldB = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Sold);
        $available = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Available);
        $hidden = $this->pair('Adidas Samba OG', 'Red/White/Black', ProductStatus::Hidden);
        $otherColour = $this->pair('Nike Dunk Low', 'Grey', ProductStatus::Sold);

        $this->attach();
        $disk = Storage::disk('public');

        // Sold pairs share one library file; the placeholder rows are replaced.
        foreach ([$soldA, $soldB] as $product) {
            $this->assertSame(['products/library/nike-dunk-low--green-white.jpg'], $product->images()->pluck('image_path')->all());
        }
        $this->assertSame('dunk green', $disk->get('products/library/nike-dunk-low--green-white.jpg'));
        // Available and hidden pairs get their own copy in their own folder.
        $availablePath = $available->images()->sole()->image_path;
        $this->assertMatchesRegularExpression("#^products/{$available->id}/attached-[\\w-]+\\.jpg$#", $availablePath);
        $this->assertSame('dunk green', $disk->get($availablePath));
        $this->assertSame('samba', $disk->get($hidden->images()->sole()->image_path));
        // No colour-specific file: falls back to the model's file.
        $this->assertSame('dunk any colour', $disk->get($otherColour->images()->sole()->image_path));

        foreach (Product::all() as $product) {
            $this->assertSame(1, $product->images()->where('is_primary', true)->count());
            $this->assertNotNull($product->primaryImage->url);
        }
    }

    public function test_products_with_a_real_photo_are_untouched_and_unknown_models_are_reported(): void
    {
        Storage::disk('public')->put('products/9/upload.jpg', 'real');
        $uploaded = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Available, 'products/9/upload.jpg');
        $unknown = $this->pair('Puma RS-X', 'Black', ProductStatus::Available);

        $this->artisan('products:attach-photos', ['dir' => $this->dir])
            ->expectsOutputToContain('1 already had a photo')
            ->expectsOutputToContain('No file for: Puma RS-X')
            ->assertSuccessful();

        $this->assertSame(['products/9/upload.jpg'], $uploaded->images()->pluck('image_path')->all());
        $this->assertSame(['products/missing.jpg'], $unknown->images()->pluck('image_path')->all());
    }

    public function test_running_again_changes_nothing_and_replace_swaps_only_attached_photos(): void
    {
        $available = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Available);
        Storage::disk('public')->put('products/9/upload.jpg', 'real');
        $uploaded = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Available, 'products/9/upload.jpg');

        $this->attach();
        $first = $available->images()->sole()->image_path;
        $this->attach();
        $this->assertSame($first, $available->images()->sole()->image_path);

        File::put($this->dir.'/nike-dunk-low--green-white.jpg', 'real dunk photo');
        $this->attach(['--replace' => true]);

        $new = $available->images()->sole()->image_path;
        $this->assertNotSame($first, $new);
        $this->assertSame('real dunk photo', Storage::disk('public')->get($new));
        Storage::disk('public')->assertMissing($first);
        $this->assertSame(['products/9/upload.jpg'], $uploaded->images()->pluck('image_path')->all());
    }

    public function test_a_missing_folder_fails(): void
    {
        $this->artisan('products:attach-photos', ['dir' => $this->dir.'/nope'])->assertFailed();
    }

    public function test_deleting_a_product_keeps_a_photo_file_other_products_share(): void
    {
        $keep = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Sold);
        $remove = $this->pair('Nike Dunk Low', 'Green/White', ProductStatus::Sold);
        $this->attach();
        $shared = 'products/library/nike-dunk-low--green-white.jpg';

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.products.destroy', $remove))
            ->assertSessionHas('success');

        Storage::disk('public')->assertExists($shared);
        $this->assertNotNull($keep->primaryImage->url);

        ProductImage::query()->where('product_id', $keep->id)->delete();
        ProductImage::deleteUnusedFiles([$shared]);
        Storage::disk('public')->assertMissing($shared);
    }
}
