<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's saved shoe size, brands and budget (profile page), used by PersonalScore.
 */
class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'shoe_size',
        'preferred_brands',
        'min_price',
        'max_price',
    ];

    protected function casts(): array
    {
        return [
            'shoe_size' => 'decimal:1',
            'preferred_brands' => 'array',
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when at least one field is filled in.
     */
    public function hasAny(): bool
    {
        return $this->shoe_size !== null || filled($this->preferred_brands)
            || $this->min_price !== null || $this->max_price !== null;
    }
}
