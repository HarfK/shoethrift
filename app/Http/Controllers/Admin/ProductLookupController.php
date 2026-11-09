<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\AttachProductPhotos;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Products\StockPhotos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON lookups for the admin product form: model name suggestions and existing photos.
 * Nothing is saved here; "Use these photos" takes effect when the form is saved.
 */
class ProductLookupController extends Controller
{
    private const MAX_NAMES = 8;

    private const MAX_THUMBNAILS = 4;

    /**
     * Distinct model names containing ?q= (any case), with the brand and category of the newest pair.
     */
    public function names(Request $request): JsonResponse
    {
        $q = $request->query('q');
        if (! is_string($q) || trim($q) === '') {
            return response()->json(['suggestions' => []]);
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($q))).'%';
        $ids = Product::query()
            ->selectRaw('max(id) as id')
            ->whereRaw("lower(name) like ? escape '!'", [$like])
            ->groupByRaw('lower(name)')
            ->orderByRaw('lower(name)')
            ->limit(self::MAX_NAMES)
            ->pluck('id');

        $suggestions = Product::query()->whereKey($ids)->orderByRaw('lower(name)')->get(['name', 'brand', 'category_id'])
            ->map(fn (Product $product) => $product->only(['name', 'brand', 'category_id']));

        return response()->json(['suggestions' => $suggestions]);
    }

    /**
     * Distinct colours of the model ?name= (same slug rule as the photo lookup), narrowed by ?q=:
     * any case, ignoring "/" and "-", colours that start with it first.
     */
    public function colors(Request $request): JsonResponse
    {
        $name = $request->query('name');
        $model = is_string($name) ? AttachProductPhotos::key($name) : '';
        if ($model === '') {
            return response()->json(['colors' => []]);
        }

        $q = $request->query('q');
        $needle = is_string($q) ? $this->squash($q) : '';

        $colors = Product::query()->whereNotNull('color')->select(['name', 'color'])->distinct()->orderBy('color')->get()
            ->filter(fn (Product $product) => AttachProductPhotos::key($product->name) === $model)
            ->pluck('color')
            ->reject(fn (string $color) => AttachProductPhotos::key($color) === '')
            ->unique(fn (string $color) => AttachProductPhotos::key($color))
            ->filter(fn (string $color) => str_contains($this->squash($color), $needle))
            ->sort(fn (string $a, string $b) => [! str_starts_with($this->squash($a), $needle), mb_strtolower($a)]
                <=> [! str_starts_with($this->squash($b), $needle), mb_strtolower($b)])
            ->values();

        return response()->json(['colors' => $colors]);
    }

    /** "Black/White", "black white" and "black-white" all become "blackwhite". */
    private function squash(string $text): string
    {
        return str_replace('-', '', AttachProductPhotos::key($text));
    }

    /**
     * Existing photos for ?name= and an exact (slug) match on ?color=: up to 4 thumbnails, and how many more the set has.
     */
    public function photos(Request $request, StockPhotos $stockPhotos): JsonResponse
    {
        $name = $request->query('name');
        $color = $request->query('color');
        $paths = is_string($name) && is_string($color) ? $stockPhotos->pathsFor($name, $color) : collect();

        return response()->json([
            'found' => $paths->isNotEmpty(),
            'photos' => $paths->take(self::MAX_THUMBNAILS)->map(fn (string $path) => asset('storage/'.$path))->values(),
            'more' => max(0, $paths->count() - self::MAX_THUMBNAILS),
        ]);
    }
}
