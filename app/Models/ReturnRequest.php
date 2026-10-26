<?php

namespace App\Models;

use App\Enums\RefundMethod;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use Database\Factories\ReturnRequestFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A customer's request to return pairs from a delivered, paid order (one per order).
 * requested → approved → refunded, or requested → rejected. Refunds are recorded by
 * App\Services\Returns\RecordRefund.
 */
class ReturnRequest extends Model
{
    /** @use HasFactory<ReturnRequestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'user_id',
        'reason',
        'details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'reason' => ReturnReason::class,
            'refund_method' => RefundMethod::class,
            'refund_amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'refund_attempted_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ReturnRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    /**
     * @return HasMany<ReturnRequestPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ReturnRequestPhoto::class);
    }

    /**
     * Price of the pairs being returned (the default refund amount).
     */
    public function itemsTotal(): string
    {
        $this->loadMissing('items.orderItem');

        return number_format($this->items->sum(fn (ReturnRequestItem $item) => (float) $item->orderItem->price * $item->quantity), 2, '.', '');
    }

    public function approve(?string $note): void
    {
        $this->decide(ReturnStatus::Approved, $note);
    }

    public function reject(?string $note): void
    {
        $this->decide(ReturnStatus::Rejected, $note);
    }

    /**
     * Only a still-requested return can be approved or rejected (row locked and re-read).
     *
     * @throws DomainException
     */
    private function decide(ReturnStatus $status, ?string $note): void
    {
        DB::transaction(function () use ($status, $note) {
            $current = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($current->status !== ReturnStatus::Requested) {
                throw new DomainException("This return is already {$current->status->label()}.");
            }

            $this->forceFill([
                'status' => $status,
                'admin_note' => $note === null || trim($note) === '' ? null : trim($note),
                'decided_at' => now(),
            ])->save();
        });
    }
}
