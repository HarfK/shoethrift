<?php

namespace App\Models;

use App\Services\Reviews\ReviewStats;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * A verified buyer's rating (0.5 to 5.0 in half stars) and comment for a product, at most one per
 * product per order. Who may review is decided by Order::reviewableItems(). Hidden reviews
 * (hidden_at) stay in the database but are left out of every public list, average and count.
 * Reviews needing an admin's look are flagged (flagged_at + flag_reasons, see flagReasons()).
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /** "Condition matched the description?" answers, 1 to 5. */
    public const CONDITION_LABELS = [
        1 => 'Not at all',
        2 => 'Mostly not',
        3 => 'Partly',
        4 => 'Mostly',
        5 => 'Exactly as described',
    ];

    public const MIN_COMMENT = 10;

    public const MAX_COMMENT = 1000;

    /** Ratings at or below this are flagged for an admin. */
    public const LOW_RATING = 2.0;

    public const FLAG_LABELS = [
        'low_rating' => 'Low rating (2★ or less)',
        'has_media' => 'Has photos or a video',
        'edited' => 'Edited by the customer',
        'video_length_unknown' => "Video length couldn't be checked",
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rating',
        'condition_rating',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'decimal:1',
            'condition_rating' => 'integer',
            'hidden_at' => 'datetime',
            'edited_at' => 'datetime',
            'flagged_at' => 'datetime',
            'flag_reasons' => 'array',
        ];
    }

    /**
     * Any change to a review (new, edited, hidden/unhidden, deleted) clears the cached
     * store/brand/category aggregates it belongs to.
     */
    protected static function booted(): void
    {
        static::saved(fn (Review $review) => ReviewStats::forgetFor($review));
        static::deleted(fn (Review $review) => ReviewStats::forgetFor($review));
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

    /**
     * All photos and videos, including hidden ones (admin).
     *
     * @return HasMany<ReviewMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ReviewMedia::class)->orderBy('type')->orderBy('position')->orderBy('id');
    }

    /**
     * What the public sees.
     *
     * @return HasMany<ReviewMedia, $this>
     */
    public function visibleMedia(): HasMany
    {
        return $this->media()->whereNull('hidden_at');
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isFlagged(): bool
    {
        return $this->flagged_at !== null;
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
     * Why this review should be checked by an admin, from its current state.
     *
     * @return list<string> keys of FLAG_LABELS
     */
    public function flagReasons(): array
    {
        $this->loadMissing('media');

        return array_values(array_filter([
            (float) $this->rating <= self::LOW_RATING ? 'low_rating' : null,
            $this->media->isNotEmpty() ? 'has_media' : null,
            $this->edited_at !== null ? 'edited' : null,
            $this->media->contains(fn (ReviewMedia $item) => $item->isVideo() && $item->duration_seconds === null) ? 'video_length_unknown' : null,
        ]));
    }

    /**
     * Flag (or unflag) from the current state. Called after a customer creates or edits a review.
     */
    public function refreshFlag(): void
    {
        $reasons = $this->flagReasons();

        $this->forceFill([
            'flagged_at' => $reasons === [] ? null : now(),
            'flag_reasons' => $reasons === [] ? null : $reasons,
        ])->save();
    }

    /**
     * An admin looked at it: clear the flag (a later edit flags it again).
     */
    public function markChecked(): void
    {
        $this->forceFill(['flagged_at' => null, 'flag_reasons' => null])->save();
    }

    /**
     * The whole store's rating from public reviews (footer, home page, /reviews). Cached.
     *
     * @return array{average: ?float, count: int, condition: ?float}
     */
    public static function storeSummary(): array
    {
        return ReviewStats::store();
    }

    /**
     * Nearest half star, for drawing averages (4.3 → 4.5, 4.2 → 4.0).
     */
    public static function roundToHalf(float $rating): float
    {
        return round($rating * 2) / 2;
    }

    /**
     * Breakdown rows 5 → 1 for a query of reviews: 5★ = 5.0, 4★ = 4.0–4.5, …, 1★ = 0.5–1.5.
     *
     * @param  Builder<Review>|Relation<Review, Model, mixed>  $reviews
     * @return array<int, int> stars => count
     */
    public static function breakdown(Builder|Relation $reviews): array
    {
        $rows = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        // Read rows, not pluck('total', 'rating'): PHP array keys would truncate 4.5 to 4.
        $counts = (clone $reviews)->reorder()->select('rating', DB::raw('count(*) as total'))->groupBy('rating')->toBase()->get();
        foreach ($counts as $row) {
            $rows[max(1, (int) floor((float) $row->rating))] += (int) $row->total;
        }

        return $rows;
    }

    /**
     * @param  Builder<Review>  $query
     */
    protected function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }
}
