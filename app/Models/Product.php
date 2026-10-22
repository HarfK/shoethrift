<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
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
        ];
    }

    /**
     * Display form of the UK shoe size: "UK 8", "UK 8.5".
     *
     * @return Attribute<string, never>
     */
    protected function sizeLabel(): Attribute
    {
        return Attribute::get(fn () => 'UK '.rtrim(rtrim((string) $this->size, '0'), '.'));
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
