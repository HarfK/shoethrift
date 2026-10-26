<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verified buyer's rating (1 to 5) and comment for a product, at most one per product per
 * order. Who may review is decided by Order::reviewableItems(). Hidden reviews (hidden_at)
 * stay in the database but are left out of every public list, average and count.
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    public const MIN_COMMENT = 10;

    public const MAX_COMMENT = 1000;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rating',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'hidden_at' => 'datetime',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Name shown publicly: first name and last initial ("Aisyah Rahman" → "Aisyah R.").
     */
    public function reviewerName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->user?->name)) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = count($parts) > 1 ? ' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : '';

        return $first.$last;
    }

    /**
     * @param  Builder<Review>  $query
     */
    protected function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }
}
