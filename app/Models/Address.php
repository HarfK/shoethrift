<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A saved shipping address in the customer's address book. Each customer with addresses has
 * exactly one default (kept by makeDefault() / deleteKeepingDefault()). Checkout copies an
 * address into the order (toShippingSnapshot()), never links to it.
 */
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * `is_default` is not fillable: change it with makeDefault().
     *
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'recipient_name',
        'phone',
        'line1',
        'line2',
        'postcode',
        'city',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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
     * The postal address as lines: line 1 / line 2 / "postcode city" / state.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_filter([
            $this->line1,
            $this->line2,
            trim($this->postcode.' '.$this->city),
            $this->state,
        ], fn ($line) => filled($line)));
    }

    /**
     * The order's copy of this address (orders.shipping_name / shipping_phone / shipping_address).
     *
     * @return array{shipping_name: string, shipping_phone: string, shipping_address: string}
     */
    public function toShippingSnapshot(): array
    {
        return [
            'shipping_name' => $this->recipient_name,
            'shipping_phone' => $this->phone,
            'shipping_address' => implode("\n", $this->lines()),
        ];
    }

    /**
     * Make this the owner's only default address.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::where('user_id', $this->user_id)->whereKeyNot($this->id)->update(['is_default' => false]);
            $this->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Delete this address; if it was the default, the most recently updated remaining one takes over.
     */
    public function deleteKeepingDefault(): void
    {
        DB::transaction(function () {
            $wasDefault = $this->is_default;
            $this->delete();

            if ($wasDefault) {
                static::where('user_id', $this->user_id)->latest('updated_at')->latest('id')->first()?->makeDefault();
            }
        });
    }
}
