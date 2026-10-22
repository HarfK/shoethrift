<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();
        $categoryId = $request->integer('category') ?: null;
        $brand = $request->string('brand')->trim()->toString();
        $status = ProductStatus::tryFrom($request->string('status')->toString());

        $products = Product::query()
            ->with(['category', 'primaryImage'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('brand', 'like', '%'.$search.'%')))
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($brand !== '', fn ($query) => $query->where('brand', $brand))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => $this->brands(),
            'statuses' => ProductStatus::cases(),
            'filters' => ['search' => $search, 'category' => $categoryId, 'brand' => $brand, 'status' => $status?->value],
        ]);
    }

    public function create(): View
    {
        return view('admin.products.create', [
            ...$this->formOptions(),
            'product' => new Product(['stock' => 1, 'status' => ProductStatus::Available]),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $storedPaths = [];

        try {
            $product = DB::transaction(function () use ($request, &$storedPaths) {
                $product = Product::create([
                    ...$request->productData(),
                    'slug' => Product::uniqueSlug($request->validated('name')),
                ]);

                $this->storeImages($product, $request->file('images', []), $storedPaths);
                $product->ensurePrimaryImage();

                return $product;
            });
        } catch (Throwable $e) {
            Storage::disk('public')->delete($storedPaths);

            throw $e;
        }

        return redirect()->route('admin.products.edit', $product)
            ->with('success', "Product \"{$product->name}\" was created.");
    }

    public function edit(Product $product): View
    {
        return view('admin.products.edit', [
            ...$this->formOptions(),
            'product' => $product->load(['images' => fn ($query) => $query->orderBy('id')]),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->fill($request->productData());

        if ($product->isDirty('name')) {
            $product->slug = Product::uniqueSlug($product->name, $product->id);
        }

        $removedImages = $product->images()->whereKey($request->validated('remove_images', []))->get();
        $storedPaths = [];

        try {
            DB::transaction(function () use ($request, $product, $removedImages, &$storedPaths) {
                $product->save();
                $product->images()->whereKey($removedImages->modelKeys())->delete();
                $this->storeImages($product, $request->file('images', []), $storedPaths);
                $product->ensurePrimaryImage($request->validated('primary_image_id'));
            });
        } catch (Throwable $e) {
            Storage::disk('public')->delete($storedPaths);

            throw $e;
        }

        // Only remove files once the database no longer points at them.
        Storage::disk('public')->delete($removedImages->pluck('image_path')->all());

        return redirect()->route('admin.products.edit', $product)
            ->with('success', "Product \"{$product->name}\" was updated.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        $orderCount = $product->orderItems()->distinct()->count('order_id');

        if ($orderCount > 0) {
            return back()->with('error', $this->inOrdersMessage($product, $orderCount));
        }

        $paths = $product->images()->pluck('image_path')->all();

        try {
            DB::transaction(function () use ($product) {
                $product->images()->delete();
                $product->delete();
            });
        } catch (QueryException $e) {
            // An order may have been placed between the check above and the delete, so the FK blocked it.
            $orderCount = $product->orderItems()->distinct()->count('order_id');

            if ($orderCount === 0) {
                throw $e;
            }

            return back()->with('error', $this->inOrdersMessage($product, $orderCount));
        }

        Storage::disk('public')->delete($paths);
        Storage::disk('public')->deleteDirectory("products/{$product->id}");

        return back()->with('success', "Product \"{$product->name}\" was deleted.");
    }

    /**
     * Store uploads under products/{id}/ and create their image rows.
     *
     * Paths are appended to $storedPaths as they are written, so the caller can clean up on failure.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  list<string>  $storedPaths
     */
    private function storeImages(Product $product, array $files, array &$storedPaths): void
    {
        foreach ($files as $file) {
            $path = $file->store("products/{$product->id}", 'public');

            if ($path === false) {
                throw new RuntimeException("Could not store image {$file->getClientOriginalName()}.");
            }

            $storedPaths[] = $path;
            $product->images()->create(['image_path' => $path]);
        }
    }

    private function inOrdersMessage(Product $product, int $orderCount): string
    {
        return "\"{$product->name}\" can't be deleted because it appears in {$orderCount} "
            .str('order')->plural($orderCount).'. Set its status to Hidden instead.';
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => $this->brands(),
            'conditions' => ProductCondition::cases(),
            'statuses' => ProductStatus::cases(),
        ];
    }

    /**
     * Brands already in use, for the filter dropdown and the form's suggestions.
     *
     * @return Collection<int, string>
     */
    private function brands(): Collection
    {
        return Product::query()->distinct()->orderBy('brand')->pluck('brand');
    }
}
