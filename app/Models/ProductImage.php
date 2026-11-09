<?php

namespace App\Models;

use App\Console\Commands\AttachProductPhotos;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'image_path',
        'is_primary',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * Public URL of the image, or null when the file is missing (e.g. seeded placeholder paths).
     *
     * Uses asset() so links follow the host being browsed rather than APP_URL.
     *
     * @return Attribute<?string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->exists($this->image_path)
            ? asset('storage/'.$this->image_path)
            : null);
    }

    /**
     * A stock photo is not a photo of this actual pair: a shared library file (products/library/)
     * or a demo copy (products/{id}/attached-*). Everything else is a real upload.
     */
    public static function isStock(string $path): bool
    {
        return AttachProductPhotos::isAttached($path);
    }

    /**
     * SQL version of isStock().
     *
     * @param  Builder<ProductImage>  $query
     */
    #[Scope]
    protected function stock(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('image_path', 'like', AttachProductPhotos::LIBRARY_DIR.'/%')
            ->orWhere('image_path', 'like', 'products/%/'.AttachProductPhotos::OWN_PREFIX.'%'));
    }

    /**
     * Files in the shared library, the only photos that can be reused on another product.
     *
     * @param  Builder<ProductImage>  $query
     */
    #[Scope]
    protected function library(Builder $query): void
    {
        $query->where('image_path', 'like', AttachProductPhotos::LIBRARY_DIR.'/%');
    }

    /**
     * Delete files from the public disk, but only those no image row points at any more.
     *
     * Sold pairs of the same model can share one photo file (products:attach-photos), so removing
     * an image from one product must never delete a file another product still shows.
     *
     * @param  list<string>  $paths
     */
    public static function deleteUnusedFiles(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths === []) {
            return;
        }

        $stillUsed = static::query()->whereIn('image_path', $paths)->distinct()->pluck('image_path')->all();
        Storage::disk('public')->delete(array_values(array_diff($paths, $stillUsed)));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
