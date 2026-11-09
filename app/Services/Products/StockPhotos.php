<?php

namespace App\Services\Products;

use App\Console\Commands\AttachProductPhotos;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Existing library photos (products/library/) for a model + colour, matched with the same rule as
 * products:import-photos ("Black/White" = "black white"). Only library files are offered: another
 * pair's upload shows that exact pair, and files under products/{id}/ (uploads and demo copies alike)
 * are removed along with that product.
 *
 * Products point at the same files; ProductImage::deleteUnusedFiles() keeps a file while any row uses it.
 */
class StockPhotos
{
    /**
     * The set's image paths, primary first. Empty when the name or colour is blank or nothing matches.
     *
     * @return Collection<int, string>
     */
    public function pathsFor(?string $name, ?string $color): Collection
    {
        $model = AttachProductPhotos::key((string) $name);
        $colour = AttachProductPhotos::key((string) $color);
        if ($model === '' || $colour === '') {
            return collect();
        }

        // The slug rule can't be written in SQL; distinct name + colour pairs keep this list short.
        $withLibraryPhotos = fn ($images) => $images->library();
        $matches = Product::query()->whereHas('images', $withLibraryPhotos)->select(['name', 'color'])->distinct()->get()
            ->filter(fn (Product $product) => AttachProductPhotos::key($product->name) === $model
                && AttachProductPhotos::key((string) $product->color) === $colour);
        if ($matches->isEmpty()) {
            return collect();
        }

        $source = Product::query()->whereHas('images', $withLibraryPhotos)
            ->whereIn('name', $matches->pluck('name'))
            ->whereIn('color', $matches->pluck('color'))
            ->latest('id')
            ->first();

        return $source->images()->library()->orderByDesc('is_primary')->orderBy('id')->pluck('image_path')
            ->filter(fn (string $path) => Storage::disk('public')->exists($path))
            ->take(ProductRequest::MAX_IMAGES)
            ->values();
    }

    /**
     * Point the product at the set for its own name and colour (first photo primary).
     *
     * @return bool whether a set was found
     */
    public function attachTo(Product $product): bool
    {
        $paths = $this->pathsFor($product->name, $product->color);

        foreach ($paths as $i => $path) {
            $product->images()->create(['image_path' => $path, 'is_primary' => $i === 0]);
        }

        return $paths->isNotEmpty();
    }
}
