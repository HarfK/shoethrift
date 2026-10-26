<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\SoleCondition;
use App\Models\Concerns\HasUniqueSlug;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUniqueSlug;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'brand',
        'description',
        'price',
        'condition',
        'size',
        'color',
        'material',
        'insole_length_cm',
        'sole_condition',
        'box_included',
        'wear_notes',
        'authenticity_notes',
        'stock',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'size' => 'decimal:1',
            'stock' => 'integer',
            'condition' => ProductCondition::class,
            'status' => ProductStatus::class,
            'insole_length_cm' => 'decimal:1',
            'sole_condition' => SoleCondition::class,
            'box_included' => 'boolean',
        ];
    }

    /**
     * Display form of the UK shoe size: "UK 8", "UK 8.5".
     *
     * @return Attribute<string, never>
     */
    protected function sizeLabel(): Attribute
    {
        return Attribute::get(fn () => 'UK '.static::formatSize($this->size));
    }

    /**
     * "8.0" → "8", "8.5" → "8.5". Works on raw DB values too (e.g. distinct sizes for filters).
     */
    public static function formatSize(float|string $size): string
    {
        return rtrim(rtrim(number_format((float) $size, 1, '.', ''), '0'), '.');
    }

    /**
     * Same rule as the available() scope, for a loaded model.
     */
    public function isAvailable(): bool
    {
        return $this->status === ProductStatus::Available && $this->stock > 0;
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * @return HasOne<ProductImage, $this>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Ensure exactly one image is primary whenever the product has images.
     *
     * The preferred image wins if it still exists; otherwise the current primary is kept,
     * falling back to the oldest image.
     */
    public function ensurePrimaryImage(?int $preferredImageId = null): void
    {
        $images = $this->images()->orderBy('id')->get();

        if ($images->isNotEmpty()) {
            $primary = $images->firstWhere('id', $preferredImageId)
                ?? $images->firstWhere('is_primary', true)
                ?? $images->first();

            $this->images()->whereKeyNot($primary->id)->where('is_primary', true)->update(['is_primary' => false]);

            if (! $primary->is_primary) {
                $primary->update(['is_primary' => true]);
            }
        }

        $this->unsetRelation('images')->unsetRelation('primaryImage');
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Adds rating_avg and reviews_count, from VISIBLE reviews only, in the same query.
     *
     * @param  Builder<Product>  $query
     */
    protected function scopeWithRatingSummary(Builder $query): void
    {
        $query->withAvg(['reviews as rating_avg' => fn ($query) => $query->visible()], 'rating')
            ->withCount(['reviews as reviews_count' => fn ($query) => $query->visible()]);
    }

    /**
     * Put returned/cancelled pairs back on sale: adds to stock, and a sold product becomes
     * available again (hidden ones stay hidden). Call inside a transaction.
     */
    public static function returnToStock(int $productId, int $quantity): void
    {
        $product = static::query()->lockForUpdate()->findOrFail($productId);
        $product->stock += $quantity;

        if ($product->status === ProductStatus::Sold) {
            $product->status = ProductStatus::Available;
        }

        $product->save();
    }

    /**
     * Products that can currently be bought.
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('status', ProductStatus::Available)->where('stock', '>', 0);
    }

    /**
     * Products shown in the storefront (available or sold, but not hidden).
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('status', '!=', ProductStatus::Hidden);
    }
}
