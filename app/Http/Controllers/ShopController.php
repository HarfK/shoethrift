<?php

namespace App\Http\Controllers;

use App\Enums\ProductCondition;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Recommendations\SimilarProducts;
use App\Services\ShoppingCart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopController extends Controller
{
    private const PER_PAGE = 12;

    private const REVIEWS_PER_PAGE = 10;

    public const SORTS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $products = $this->applyFilters(Product::available(), $filters)
            ->with(['primaryImage', 'category'])
            ->withRatingSummary()
            ->tap(fn (Builder $query) => match ($filters['sort']) {
                'price_asc' => $query->orderBy('price')->orderByDesc('id'),
                'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
                default => $query->latest()->latest('id'),
            })
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Filter options only list values that currently have stock, so no choice leads to an empty page.
        return view('shop.index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::whereHas('products', fn ($query) => $query->available())
                ->withCount(['products as available_count' => fn ($query) => $query->available()])
                ->orderBy('name')
                ->get(),
            'brands' => Product::available()->distinct()->orderBy('brand')->pluck('brand'),
            'sizes' => Product::available()->distinct()->orderBy('size')->pluck('size')
                ->map(fn ($size) => Product::formatSize($size))
                ->unique()
                ->values(),
            'conditions' => ProductCondition::cases(),
            // Only materials with available pairs, in the config's order.
            'materials' => array_values(array_intersect(config('shop.materials'),
                Product::available()->whereNotNull('material')->distinct()->pluck('material')->all())),
            'sorts' => self::SORTS,
        ]);
    }

    public function show(Request $request, string $slug, ShoppingCart $cart, SimilarProducts $similarProducts): View
    {
        // Sold products stay reachable so shared links keep working; hidden ones 404.
        $product = Product::visible()
            ->where('slug', $slug)
            ->with(['category', 'images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id')])
            ->withRatingSummary()
            ->firstOrFail();

        return view('shop.show', [
            'product' => $product,
            'inCart' => $cart->quantityOf($product),
            'reviews' => $product->reviews()->visible()->with('user')->latest()->latest('id')
                ->paginate(self::REVIEWS_PER_PAGE, pageName: 'reviews_page')->withQueryString()->fragment('reviews'),
            // stars => count of visible reviews, 5 down to 1
            'ratingBreakdown' => collect(range(5, 1))->mapWithKeys(fn (int $stars) => [$stars => 0])
                ->replace($product->reviews()->visible()->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating')->map(fn ($count) => (int) $count)),
            'reviewableItem' => $this->reviewableItemFor($request, $product),
            'recommendations' => $similarProducts->for($product),
        ]);
    }

    /**
     * An order item through which the logged-in customer can still review this product, if any.
     */
    private function reviewableItemFor(Request $request, Product $product): ?OrderItem
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        return $user->orders()
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->latest('id')
            ->get()
            ->flatMap(fn (Order $order) => $order->reviewableItems()->where('product_id', $product->id))
            ->first()
            ?->load('order');
    }

    /**
     * Read filters from the query string. Invalid values are ignored rather than rejected.
     *
     * @return array{search: string, category: ?Category, brand: ?string, size: ?string, conditions: list<ProductCondition>, material: ?string, box: bool, min_price: ?float, max_price: ?float, sort: string}
     */
    private function filters(Request $request): array
    {
        $size = $request->query('size');
        $size = is_numeric($size) && $size >= 1 && $size <= 15 ? Product::formatSize($size) : null;

        $conditions = collect((array) $request->query('condition', []))
            ->filter(fn ($value) => is_string($value))
            ->map(fn (string $value) => ProductCondition::tryFrom($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $minPrice = $this->price($request->query('min_price'));
        $maxPrice = $this->price($request->query('max_price'));

        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
        }

        $material = $request->query('material');

        $categorySlug = $request->query('category');
        $brand = $request->query('brand');
        $sort = $request->query('sort');

        return [
            'search' => trim((string) (is_string($request->query('search')) ? $request->query('search') : '')),
            'category' => is_string($categorySlug) && $categorySlug !== '' ? Category::where('slug', $categorySlug)->first() : null,
            'brand' => is_string($brand) && $brand !== '' ? $brand : null,
            'size' => $size,
            'material' => is_string($material) && in_array($material, config('shop.materials'), true) ? $material : null,
            'box' => $request->query('box') === '1',
            'conditions' => $conditions,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'sort' => is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : 'newest',
        ];
    }

    private function price(mixed $value): ?float
    {
        return is_numeric($value) && $value >= 0 ? (float) $value : null;
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$filters['search'].'%')
                ->orWhere('brand', 'like', '%'.$filters['search'].'%')))
            ->when($filters['category'], fn (Builder $query, Category $category) => $query->whereBelongsTo($category))
            ->when($filters['brand'], fn (Builder $query, string $brand) => $query->where('brand', $brand))
            ->when($filters['size'] !== null, fn (Builder $query) => $query->where('size', (float) $filters['size']))
            ->when($filters['conditions'], fn (Builder $query, array $conditions) => $query
                ->whereIn('condition', array_map(fn (ProductCondition $c) => $c->value, $conditions)))
            ->when($filters['material'], fn (Builder $query, string $material) => $query->where('material', $material))
            ->when($filters['box'], fn (Builder $query) => $query->where('box_included', true))
            ->when($filters['min_price'] !== null, fn (Builder $query) => $query->where('price', '>=', $filters['min_price']))
            ->when($filters['max_price'] !== null, fn (Builder $query) => $query->where('price', '<=', $filters['max_price']));
    }
}
