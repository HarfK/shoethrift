<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * For models with a unique `slug` column generated from a name.
 */
trait HasUniqueSlug
{
    /**
     * Build a slug from the name that no other row uses ("sneakers", then "sneakers-2", ...).
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::snake(class_basename(static::class));
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
