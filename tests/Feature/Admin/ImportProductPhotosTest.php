<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * products:import-photos: photos per model and colour from {folder}/{model}/{colour}/, and --make-folders.
 */
class ImportProductPhotosTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()).'/import-photos-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files  file name => content
     */
    private function photos(string $folder, array $files): void
    {
        File::ensureDirectoryExists("{$this->dir}/{$folder}");
        foreach ($files as $name => $content) {
            File::put("{$this->dir}/{$folder}/{$name}", $content);
        }
    }

    private function pair(string $name = 'Adidas Samba OG', ?string $color = 'Black', ProductStatus $status = ProductStatus::Sold, ?string $image = 'products/library/adidas-samba-og--black.jpg'): Product
    {
        $product = Product::factory()->create(['name' => $name, 'color' => $color, 'status' => $status, 'stock' => $status === ProductStatus::Sold ? 0 : 1]);
        if ($image !== null) {
            Storage::disk('public')->put($image, 'demo');
            $product->images()->create(['image_path' => $image, 'is_primary' => true]);
        }

        return $product;
    }

    private function import(array $options = []): void
    {
        $this->artisan('products:import-photos', ['folder' => $this->dir, ...$options])->assertSuccessful();
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    private function imagesOf(Product $product): array
    {
        return $product->images()->orderBy('id')->get()->map(fn ($image) => [$image->image_path, $image->is_primary])->all();
    }

    public function test_it_gives_every_matching_pair_the_set_in_natural_order_with_the_first_as_primary(): void
    {
        $a = $this->pair();
        $b = $this->pair();
        $otherColor = $this->pair(color: 'White', image: 'products/library/adidas-samba-og--white.jpg');
        $otherModel = $this->pair(name: 'Nike Dunk Low', image: 'products/library/nike-dunk-low--black.jpg');
        $this->photos('adidas-samba-og/black', ['10.jpg' => 'ten', '2.PNG' => 'two', '1.jpg' => 'one', 'notes.txt' => 'skip', 'empty.jpg' => '']);

        $this->import();

        $expected = [
            ['products/library/adidas-samba-og--black-1.jpg', true],
            ['products/library/adidas-samba-og--black-2.png', false],
            ['products/library/adidas-samba-og--black-3.jpg', false],
        ];
        $this->assertSame($expected, $this->imagesOf($a));
        $this->assertSame($expected, $this->imagesOf($b));
        $this->assertSame('ten', Storage::disk('public')->get('products/library/adidas-samba-og--black-3.jpg'));
        $this->assertSame(1, $a->images()->where('is_primary', true)->count());

        // Others are untouched; the demo file nobody uses any more is gone.
        $this->assertSame([['products/library/adidas-samba-og--white.jpg', true]], $this->imagesOf($otherColor));
        $this->assertSame([['products/library/nike-dunk-low--black.jpg', true]], $this->imagesOf($otherModel));
        Storage::disk('public')->assertMissing('products/library/adidas-samba-og--black.jpg');
    }

    public function test_a_color_with_a_slash_uses_dashes_in_its_folder(): void
    {
        $pair = $this->pair(name: 'Dr. Martens 1460 Boot', color: 'Red/White/Black', image: null);
        $this->photos('dr-martens-1460-boot/red-white-black', ['1.webp' => 'x']);

        $this->import();

        $this->assertSame([['products/library/dr-martens-1460-boot--red-white-black-1.webp', true]], $this->imagesOf($pair));
    }

    public function test_demo_copies_are_replaced_and_real_uploads_are_kept(): void
    {
        $available = $this->pair(status: ProductStatus::Available, image: 'products/77/attached-abc.jpg');
        $uploaded = $this->pair(status: ProductStatus::Available, image: 'products/88/real-upload.jpg');
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'one', '2.jpg' => 'two']);

        $this->import();

        $this->assertSame([['products/library/adidas-samba-og--black-1.jpg', true], ['products/library/adidas-samba-og--black-2.jpg', false]], $this->imagesOf($available));
        Storage::disk('public')->assertMissing('products/77/attached-abc.jpg');

        // The real upload stays, no longer primary: the new set's first photo is.
        $this->assertSame([['products/88/real-upload.jpg', false], ['products/library/adidas-samba-og--black-1.jpg', true], ['products/library/adidas-samba-og--black-2.jpg', false]], $this->imagesOf($uploaded));
        Storage::disk('public')->assertExists('products/88/real-upload.jpg');
    }

    public function test_running_twice_changes_nothing(): void
    {
        $pair = $this->pair();
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'one', '2.jpg' => 'two']);

        $this->import();
        $ids = $pair->images()->orderBy('id')->pluck('id')->all();
        $this->artisan('products:import-photos', ['folder' => $this->dir])
            ->expectsOutputToContain('adidas-samba-og/black: 1 products, 2 photos, 0 changed')
            ->assertSuccessful();

        $this->assertSame($ids, $pair->images()->orderBy('id')->pluck('id')->all());
    }

    public function test_a_changed_set_replaces_the_old_one_and_removes_files_nobody_uses(): void
    {
        $pair = $this->pair();
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'one', '2.jpg' => 'two', '3.jpg' => 'three']);
        $this->import();

        File::delete("{$this->dir}/adidas-samba-og/black/3.jpg");
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'better photo']);
        $this->import();

        $this->assertCount(2, $this->imagesOf($pair));
        $this->assertSame('better photo', Storage::disk('public')->get('products/library/adidas-samba-og--black-1.jpg'));
        Storage::disk('public')->assertMissing('products/library/adidas-samba-og--black-3.jpg');
    }

    public function test_an_empty_folder_removes_nothing(): void
    {
        $pair = $this->pair();
        $this->photos('adidas-samba-og/black', []);

        $this->artisan('products:import-photos', ['folder' => $this->dir])
            ->expectsOutputToContain('Matched folders (0):')
            ->expectsOutputToContain('Adidas Samba OG / Black (1 products): adidas-samba-og/black')
            ->assertSuccessful();

        $this->assertSame([['products/library/adidas-samba-og--black.jpg', true]], $this->imagesOf($pair));
    }

    public function test_the_report_lists_matches_unmatched_folders_and_what_is_missing(): void
    {
        $this->pair();
        $this->pair(color: 'Beige', image: 'products/library/adidas-samba-og--beige.jpg');
        $this->pair(name: 'Nike Dunk Low', image: 'products/library/nike-dunk-low--black.jpg');
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'one']);
        $this->photos('adidas-samba-og/pink', ['1.jpg' => 'one']);
        $this->photos('made-up-shoe/black', ['1.jpg' => 'one']);

        $this->artisan('products:import-photos', ['folder' => $this->dir])
            ->expectsOutputToContain('adidas-samba-og/black: 1 products, 1 photos, 1 changed')
            ->expectsOutputToContain('adidas-samba-og/pink: colours for this model: beige, black')
            ->expectsOutputToContain('made-up-shoe/black: no product has this model name')
            ->expectsOutputToContain('Adidas Samba OG / Beige (1 products): adidas-samba-og/beige')
            ->expectsOutputToContain('Nike Dunk Low / Black (1 products): nike-dunk-low/black')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing('products/library/adidas-samba-og--pink-1.jpg');
    }

    public function test_a_set_imported_earlier_does_not_count_as_missing(): void
    {
        $this->pair();
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'one']);
        $this->import();
        File::deleteDirectory("{$this->dir}/adidas-samba-og");

        $this->artisan('products:import-photos', ['folder' => $this->dir])
            ->expectsOutputToContain('In the database with no photos yet (0):')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_and_changes_nothing(): void
    {
        $pair = $this->pair();
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'one']);

        $this->artisan('products:import-photos', ['folder' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('adidas-samba-og/black: 1 products, 1 photos, 1 would change')
            ->expectsOutputToContain('Dry run, nothing was changed.')
            ->assertSuccessful();

        $this->assertSame([['products/library/adidas-samba-og--black.jpg', true]], $this->imagesOf($pair));
        Storage::disk('public')->assertMissing('products/library/adidas-samba-og--black-1.jpg');
    }

    public function test_a_missing_folder_fails(): void
    {
        $this->artisan('products:import-photos', ['folder' => $this->dir.'/nope'])->assertFailed();
    }

    public function test_make_folders_creates_the_structure_and_a_checklist_highest_first(): void
    {
        $this->pair(color: 'Red/White/Black', image: null);
        $this->pair(name: 'Nike Dunk Low', color: 'White', image: null);
        $this->pair(name: 'Nike Dunk Low', color: 'White', image: null);
        $this->pair(name: 'Puma RS-X', color: null, image: null);

        $this->artisan('products:import-photos', ['folder' => $this->dir, '--make-folders' => true])
            ->expectsOutputToContain('Created 3 folders; 0 already existed.')
            ->assertSuccessful();

        $this->assertDirectoryExists("{$this->dir}/adidas-samba-og/red-white-black");
        $this->assertDirectoryExists("{$this->dir}/nike-dunk-low/white");
        $this->assertDirectoryExists("{$this->dir}/puma-rs-x/no-color");

        $rows = array_map('str_getcsv', file("{$this->dir}/CHECKLIST.csv", FILE_IGNORE_NEW_LINES));
        $this->assertSame(['model', 'color', 'folder_path', 'number_of_products'], $rows[0]);
        $this->assertSame(['Nike Dunk Low', 'White', "{$this->dir}/nike-dunk-low/white", '2'], $rows[1]);
        $this->assertSame(['Adidas Samba OG', 'Red/White/Black', "{$this->dir}/adidas-samba-og/red-white-black", '1'], $rows[2]);
        $this->assertCount(4, $rows);
    }

    public function test_make_folders_never_overwrites_and_can_be_a_dry_run(): void
    {
        $this->pair(image: null);

        $this->artisan('products:import-photos', ['folder' => $this->dir, '--make-folders' => true, '--dry-run' => true])
            ->expectsOutputToContain('Would create 1 folders')
            ->assertSuccessful();
        $this->assertDirectoryDoesNotExist("{$this->dir}/adidas-samba-og");
        $this->assertFileDoesNotExist("{$this->dir}/CHECKLIST.csv");

        $this->photos('adidas-samba-og/black', ['1.jpg' => 'mine']);
        File::put("{$this->dir}/CHECKLIST.csv", 'my notes');

        $this->artisan('products:import-photos', ['folder' => $this->dir, '--make-folders' => true])
            ->expectsOutputToContain('already exists')
            ->expectsOutputToContain('Created 0 folders; 1 already existed.')
            ->assertSuccessful();

        $this->assertSame('my notes', File::get("{$this->dir}/CHECKLIST.csv"));
        $this->assertSame('mine', File::get("{$this->dir}/adidas-samba-og/black/1.jpg"));
    }

    public function test_the_product_page_shows_all_photos_with_the_primary_first(): void
    {
        $pair = $this->pair(status: ProductStatus::Available, image: 'products/9/real-upload.jpg');
        $this->photos('adidas-samba-og/black', ['1.jpg' => 'one', '2.jpg' => 'two', '3.jpg' => 'three']);
        $this->import();

        $html = $this->get(route('shop.show', $pair->slug))->assertOk()->getContent();

        preg_match_all('#<img id="photo-\d+" src="([^"]+)"#', $html, $gallery);
        $this->assertSame([
            asset('storage/products/library/adidas-samba-og--black-1.jpg'),
            asset('storage/products/9/real-upload.jpg'),
            asset('storage/products/library/adidas-samba-og--black-2.jpg'),
            asset('storage/products/library/adidas-samba-og--black-3.jpg'),
        ], $gallery[1]);
        $this->assertStringContainsString('href="#photo-4"', $html);
    }
}
