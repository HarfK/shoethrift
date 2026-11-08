<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Builder;
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

    /** Sortable columns (?sort=...&direction=asc|desc); anything else falls back to the default. */
    public const SORTS = ['name', 'category', 'brand', 'price', 'condition', 'stock', 'status', 'created_at'];

    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    /** Logical (not alphabetical) orders for the enum columns. */
    private const CONDITION_ORDER = ['like_new', 'good', 'fair'];

    private const STATUS_ORDER = ['available', 'hidden', 'sold'];

    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();
        $categoryId = $request->integer('category') ?: null;
        $brand = $request->string('brand')->trim()->toString();
        $status = ProductStatus::tryFrom($request->string('status')->toString());
        // UK size range, e.g. from the Restock page (size band S/M/L). Non-numbers are ignored.
        $sizeMin = is_numeric($request->query('size_min')) ? (float) $request->query('size_min') : null;
        $sizeMax = is_numeric($request->query('size_max')) ? (float) $request->query('size_max') : null;

        [$sort, $direction] = $this->sorting($request);

        // Columns are qualified with "products." because sorting by category joins the categories table.
        $query = Product::query()
            ->select('products.*')
            ->with(['category', 'primaryImage'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('products.brand', 'like', '%'.$search.'%')))
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->when($brand !== '', fn ($query) => $query->where('products.brand', $brand))
            ->when($status, fn ($query) => $query->where('products.status', $status))
            ->when($sizeMin !== null, fn ($query) => $query->where('products.size', '>=', $sizeMin))
            ->when($sizeMax !== null, fn ($query) => $query->where('products.size', '<=', $sizeMax));

        $this->applySort($query, $sort, $direction);

        $products = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => $this->brands(),
            'statuses' => ProductStatus::cases(),
            'filters' => ['search' => $search, 'category' => $categoryId, 'brand' => $brand, 'status' => $status?->value,
                'size_min' => $sizeMin, 'size_max' => $sizeMax],
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * The requested sort, whitelisted: an unknown column means the default (newest first);
     * a known column with a missing or unknown direction sorts ascending.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    private function sorting(Request $request): array
    {
        $sort = $request->query('sort');
        if (! is_string($sort) || ! in_array($sort, self::SORTS, true)) {
            return [self::DEFAULT_SORT, self::DEFAULT_DIRECTION];
        }
        $direction = $request->query('direction');

        return [$sort, in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc'];
    }

    /**
     * Category sorts by category name, condition by Like new > Good > Fair, status by Available > Hidden > Sold.
     * Ties are broken by id (same direction) so pagination is stable.
     *
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $grammar = DB::getQueryGrammar();
        $rank = function (string $column, array $order) use ($grammar, $direction): string {
            $cases = implode(' ', array_map(fn ($value, $i) => "when '{$value}' then {$i}", $order, array_keys($order)));

            return "case {$grammar->wrap($column)} {$cases} else ".count($order)." end {$direction}";
        };

        match ($sort) {
            'category' => $query->leftJoin('categories as sort_categories', 'sort_categories.id', '=', 'products.category_id')
                ->orderBy('sort_categories.name', $direction),
            'condition' => $query->orderByRaw($rank('products.condition', self::CONDITION_ORDER)),
            'status' => $query->orderByRaw($rank('products.status', self::STATUS_ORDER)),
            default => $query->orderBy('products.'.$sort, $direction),
        };
        $query->orderBy('products.id', $direction);
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
        ProductImage::deleteUnusedFiles($removedImages->pluck('image_path')->all());

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

        ProductImage::deleteUnusedFiles($paths);
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
