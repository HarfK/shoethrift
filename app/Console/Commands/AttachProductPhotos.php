<?php

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Link photo files to products by model name (and colour), for products that have no photo file.
 *
 * Files in the folder are named "{model-slug}--{colour-slug}.jpg" (e.g. nike-dunk-low--green-white.jpg)
 * or just "{model-slug}.jpg" for every colour. Available and hidden pairs get their OWN copy under
 * products/{id}/ (so editing or deleting one never touches another product); sold pairs share one
 * file per model under products/library/. Products that already show a photo are never touched;
 * --replace also swaps photos this command attached earlier (never admin uploads).
 *
 * The demo images come from scripts/make_demo_photos.py (storage/app/demo-photos).
 */
class AttachProductPhotos extends Command
{
    /** Path markers of files this command created, so --replace never touches admin uploads. */
    public const LIBRARY_DIR = 'products/library';

    public const OWN_PREFIX = 'attached-';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    protected $signature = 'products:attach-photos
        {dir? : Folder with the photo files (default storage/app/demo-photos)}
        {--replace : Also replace photos this command attached before}';

    protected $description = 'Attach photo files to products that have no photo, matched by model name and colour';

    public function handle(): int
    {
        $dir = $this->argument('dir') ?: storage_path('app/demo-photos');
        if (! is_dir($dir)) {
            $this->error("Folder not found: {$dir}");

            return self::FAILURE;
        }

        $files = $this->indexFiles($dir);
        if ($files === []) {
            $this->error("No .jpg/.jpeg/.png/.webp files in {$dir}");

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $replace = (bool) $this->option('replace');
        $stats = ['own' => 0, 'shared' => 0, 'kept' => 0];
        $missing = [];
        $libraryDone = [];

        Product::query()->with('images')->chunkById(200, function ($products) use ($disk, $files, $replace, &$stats, &$missing, &$libraryDone) {
            foreach ($products as $product) {
                $keep = $product->images->filter(fn (ProductImage $image) => $disk->exists($image->image_path)
                    && ! ($replace && self::isAttached($image->image_path)));
                if ($keep->isNotEmpty()) {
                    $stats['kept']++;

                    continue;
                }

                $source = $files[self::key($product->name).'--'.self::key((string) $product->color)]
                    ?? $files[self::key($product->name)] ?? null;
                if ($source === null) {
                    $missing[$product->name] = true;

                    continue;
                }

                $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
                if ($product->status === ProductStatus::Sold) {
                    $path = self::LIBRARY_DIR.'/'.basename($source);
                    if (! isset($libraryDone[$path])) {
                        $disk->put($path, file_get_contents($source));
                        $libraryDone[$path] = true;
                    }
                    $stats['shared']++;
                } else {
                    $path = "products/{$product->id}/".self::OWN_PREFIX.Str::uuid().'.'.$extension;
                    $disk->put($path, file_get_contents($source));
                    $stats['own']++;
                }

                $oldPaths = $product->images->pluck('image_path')->all();
                DB::transaction(function () use ($product, $path) {
                    $product->images()->delete(); // placeholder rows (no file) or replaced attachments
                    $image = $product->images()->create(['image_path' => $path, 'is_primary' => true]);
                    $product->ensurePrimaryImage($image->id);
                });
                ProductImage::deleteUnusedFiles(array_values(array_diff($oldPaths, [$path])));
            }
        });

        $this->info(sprintf(
            'Photos attached: %s products (%s own copies for available/hidden pairs, %s sold pairs sharing %s library files). %s already had a photo.',
            number_format($stats['own'] + $stats['shared']), number_format($stats['own']), number_format($stats['shared']),
            count($libraryDone), number_format($stats['kept']),
        ));
        if ($missing !== []) {
            $this->warn('No file for: '.implode(', ', array_keys($missing)));
        }

        return self::SUCCESS;
    }

    /** "Red/White/Black" -> "red-white-black" (same rule as scripts/make_demo_photos.py). */
    public static function key(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace('&', 'and', $text))), '-');
    }

    public static function isAttached(string $path): bool
    {
        return str_starts_with($path, self::LIBRARY_DIR.'/') || str_starts_with(basename($path), self::OWN_PREFIX);
    }

    /**
     * @return array<string, string> lower-case file name without extension => full path
     */
    private function indexFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            if (is_file($full) && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                $files[strtolower(pathinfo($name, PATHINFO_FILENAME))] = $full;
            }
        }

        return $files;
    }
}
