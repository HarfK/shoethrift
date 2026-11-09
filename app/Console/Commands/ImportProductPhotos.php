<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Imports real photos per model and colour from {folder}/{model-slug}/{color-slug}/ (e.g. adidas-samba-og/black/1.jpg),
 * or creates that empty folder structure and a CHECKLIST.csv with --make-folders.
 *
 * Every pair of a model + colour gets the same set of photos, shared from products/library/ (safe because
 * ProductImage::deleteUnusedFiles() never removes a file another product still uses). The set replaces the
 * placeholders (AttachProductPhotos::isAttached(): products/library/ and the demo copies in products/{id}/attached-*);
 * real uploads are kept, and the first photo of the set becomes the primary. Running it again changes nothing.
 */
class ImportProductPhotos extends Command
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Folder name for products without a colour. */
    private const NO_COLOR = 'no-color';

    protected $signature = 'products:import-photos
        {folder : Folder holding model-slug/color-slug/ subfolders of images}
        {--dry-run : Show what would happen and change nothing}
        {--make-folders : Create the empty folders and CHECKLIST.csv from the products table instead of importing}';

    protected $description = 'Import product photos per model and colour from a folder, or create that folder structure';

    public function handle(): int
    {
        $folder = rtrim(str_replace('\\', '/', (string) $this->argument('folder')), '/');
        $combos = $this->combos();

        return $this->option('make-folders') ? $this->makeFolders($folder, $combos) : $this->import($folder, $combos);
    }

    /**
     * Every model + colour in the products table, most products first.
     *
     * @return Collection<string, array{model: string, color_slug: string, name: string, color: string, ids: list<int>}>
     */
    private function combos(): Collection
    {
        return Product::query()->orderBy('id')->get(['id', 'name', 'color'])
            ->groupBy(fn (Product $product) => $this->folderFor($product))
            ->map(fn (Collection $products, string $key) => [
                'model' => strtok($key, '/'),
                'color_slug' => substr($key, strpos($key, '/') + 1),
                'name' => $products->first()->name,
                'color' => (string) $products->first()->color,
                'ids' => $products->pluck('id')->all(),
            ])
            ->sort(fn (array $a, array $b) => [count($b['ids']), $a['name'], $a['color']] <=> [count($a['ids']), $b['name'], $b['color']]);
    }

    private function folderFor(Product $product): string
    {
        return AttachProductPhotos::key($product->name).'/'.(AttachProductPhotos::key((string) $product->color) ?: self::NO_COLOR);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $combos
     */
    private function makeFolders(string $folder, Collection $combos): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        foreach ($combos as $key => $combo) {
            if (is_dir("{$folder}/{$key}")) {
                continue;
            }
            $created++;
            if (! $dryRun) {
                File::ensureDirectoryExists("{$folder}/{$key}");
            }
        }

        $checklist = "{$folder}/CHECKLIST.csv";
        if (file_exists($checklist)) {
            $this->warn("{$checklist} already exists, so it was left as it is.");
        } elseif (! $dryRun) {
            File::ensureDirectoryExists($folder);
            $out = fopen($checklist, 'w');
            fputcsv($out, ['model', 'color', 'folder_path', 'number_of_products']);
            foreach ($combos as $key => $combo) {
                fputcsv($out, [$combo['name'], $combo['color'], "{$folder}/{$key}", count($combo['ids'])]);
            }
            fclose($out);
            $this->info("Wrote {$checklist}.");
        }

        $this->info(($dryRun ? 'Dry run, nothing was changed. Would create ' : 'Created ')."{$created} folders; ".($combos->count() - $created).' already existed.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $combos
     */
    private function import(string $folder, Collection $combos): int
    {
        if (! is_dir($folder)) {
            $this->error("Folder not found: {$folder}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $matched = [];
        $unmatched = [];
        $withPhotos = [];

        foreach ($this->colorFolders($folder) as $key => $path) {
            if (! $combos->has($key)) {
                $unmatched[] = $key;

                continue;
            }
            $files = $this->imagesIn($path);
            if ($files === []) {
                continue; // an empty folder (e.g. from --make-folders) must not remove anything
            }
            $withPhotos[$key] = true;
            $matched[$key] = [count($combos[$key]['ids']), count($files), $this->applySet($key, $files, $combos[$key]['ids'], $dryRun)];
        }

        $this->report($combos, $matched, $unmatched, $withPhotos + $this->alreadyImported(), $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> "model/colour" => path
     */
    private function colorFolders(string $folder): array
    {
        $found = [];
        foreach (File::directories($folder) as $modelDir) {
            foreach (File::directories($modelDir) as $colorDir) {
                $found[basename($modelDir).'/'.basename($colorDir)] = $colorDir;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Image files in natural file-name order (2.jpg before 10.jpg).
     *
     * @return list<SplFileInfo>
     */
    private function imagesIn(string $path): array
    {
        $files = array_filter(File::files($path), fn (SplFileInfo $file) => in_array(strtolower($file->getExtension()), self::EXTENSIONS, true) && $file->getSize() > 0);
        usort($files, fn (SplFileInfo $a, SplFileInfo $b) => strnatcasecmp($a->getFilename(), $b->getFilename()));

        return $files;
    }

    /**
     * Copies the set into the library and gives it to every product that doesn't have it yet.
     *
     * @param  list<SplFileInfo>  $files
     * @param  list<int>  $ids
     * @return int how many products changed (or would change)
     */
    private function applySet(string $key, array $files, array $ids, bool $dryRun): int
    {
        $disk = Storage::disk('public');
        $paths = [];
        foreach (array_values($files) as $i => $file) {
            $path = AttachProductPhotos::LIBRARY_DIR.'/'.str_replace('/', '--', $key).'-'.($i + 1).'.'.strtolower($file->getExtension());
            $paths[] = $path;
            if (! $dryRun && (! $disk->exists($path) || md5($disk->get($path)) !== md5_file($file->getPathname()))) {
                $disk->put($path, file_get_contents($file->getPathname()));
            }
        }

        $stale = Product::query()->whereKey($ids)->with(['images' => fn ($query) => $query->orderBy('id')])->get()
            ->reject(fn (Product $product) => $this->hasSet($product, $paths));
        if ($dryRun || $stale->isEmpty()) {
            return $stale->count();
        }

        $oldPaths = [];
        DB::transaction(function () use ($stale, $paths, &$oldPaths) {
            foreach ($stale as $product) {
                $placeholders = $product->images->filter(fn (ProductImage $image) => AttachProductPhotos::isAttached($image->image_path));
                $oldPaths = [...$oldPaths, ...$placeholders->pluck('image_path')->all()];
                $product->images()->whereKey($placeholders->modelKeys())->delete();

                $primaryId = null;
                foreach ($paths as $i => $path) {
                    $image = $product->images()->create(['image_path' => $path, 'is_primary' => $i === 0]);
                    $primaryId ??= $image->id;
                }
                $product->ensurePrimaryImage($primaryId);
            }
        });
        ProductImage::deleteUnusedFiles(array_values(array_diff($oldPaths, $paths)));

        return $stale->count();
    }

    /**
     * @param  list<string>  $paths
     */
    private function hasSet(Product $product, array $paths): bool
    {
        $placeholders = $product->images->filter(fn (ProductImage $image) => AttachProductPhotos::isAttached($image->image_path));

        return $placeholders->pluck('image_path')->values()->all() === $paths
            && $product->images->where('is_primary', true)->pluck('image_path')->values()->all() === [$paths[0]];
    }

    /**
     * Model/colour folders imported in an earlier run ("…--black-2.jpg"; demo photos have no number).
     *
     * @return array<string, true>
     */
    private function alreadyImported(): array
    {
        $found = [];
        foreach (ProductImage::query()->where('image_path', 'like', AttachProductPhotos::LIBRARY_DIR.'/%')->distinct()->pluck('image_path') as $path) {
            if (preg_match('#/([^/]+)--([^/]+)-\d+\.\w+$#', $path, $m)) {
                $found["{$m[1]}/{$m[2]}"] = true;
            }
        }

        return $found;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $combos
     * @param  array<string, array{0: int, 1: int, 2: int}>  $matched
     * @param  list<string>  $unmatched
     * @param  array<string, true>  $withPhotos
     */
    private function report(Collection $combos, array $matched, array $unmatched, array $withPhotos, bool $dryRun): void
    {
        $this->info('Matched folders ('.count($matched).'):');
        foreach ($matched as $key => [$products, $photos, $changed]) {
            $this->line("  {$key}: {$products} products, {$photos} photos, ".($dryRun ? "{$changed} would change" : "{$changed} changed"));
        }

        $this->newLine();
        $this->info('Folders that matched no products ('.count($unmatched).'):');
        foreach ($unmatched as $key) {
            $model = strtok($key, '/');
            $colors = $combos->where('model', $model)->pluck('color_slug')->sort()->values();
            $this->warn("  {$key}: ".($colors->isEmpty() ? 'no product has this model name' : 'colours for this model: '.$colors->implode(', ')));
        }

        $missing = $combos->reject(fn (array $combo, string $key) => isset($withPhotos[$key]));
        $this->newLine();
        $this->info('In the database with no photos yet ('.$missing->count().'):');
        foreach ($missing as $key => $combo) {
            $this->line("  {$combo['name']} / ".($combo['color'] ?: 'no colour').' ('.count($combo['ids'])." products): {$key}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run, nothing was changed.');
        }
    }
}
