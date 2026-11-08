<?php

namespace App\Models;

use App\Enums\PointTransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the loyalty points ledger. Written only by App\Services\Loyalty\PointsService.
 * Rows that add points are "lots" (expires_at + remaining); see the migration for the FIFO design.
 */
class PointTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'order_id', 'type', 'points', 'balance_after', 'description', 'expires_at', 'remaining', 'allocations', 'admin_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PointTransactionType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'remaining' => 'integer',
            'allocations' => 'array',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Lots that still have points left (whether or not they have expired yet).
     *
     * @param  Builder<PointTransaction>  $query
     */
    public function scopeOpenLots(Builder $query): void
    {
        $query->whereNotNull('expires_at')->where('remaining', '>', 0);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
