<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

/**
 * Implements MustVerifyEmail: registering sends Laravel's verification email, and checkout
 * requires a verified email (`verified` middleware).
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * `role` is deliberately excluded so it can never be set from request input;
     * assign it explicitly instead (e.g. $user->role = UserRole::Admin).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Keep phone_normalized in sync with phone, so the unique index catches the same number
     * typed differently ("012-345 6789" vs "+60123456789").
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('phone') || ($user->phone !== null && $user->phone_normalized === null)) {
                $user->phone_normalized = self::normalizePhone($user->phone);
            }
        });
    }

    /**
     * A Malaysian phone number as digits with the country code: "012-345 6789", "0123456789" and
     * "+60 12-345 6789" all become "60123456789"; "03-1234 5678" becomes "60312345678".
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return match (true) {
            $digits === '' => null,
            str_starts_with($digits, '60') => $digits,
            str_starts_with($digits, '0') => '60'.substr($digits, 1),
            default => $digits,
        };
    }

    /**
     * Validation rule: no other account uses this phone number (in any formatting).
     */
    public static function uniquePhoneRule(?int $ignoreUserId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreUserId) {
            $taken = static::query()
                ->where('phone_normalized', self::normalizePhone(is_string($value) ? $value : null))
                ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
                ->exists();

            if ($taken) {
                $fail('This phone number is already registered to another account.');
            }
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * @return HasOne<Address, $this>
     */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    /**
     * Phone verified with the (demo) OTP, for the CURRENT phone number. Changing the number
     * clears phone_verified_at (ProfileController).
     */
    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null && filled($this->phone);
    }

    /**
     * Public URL of the profile photo, or null (show initials instead). Uses asset() like
     * ProductImage::url, so it ignores APP_URL (works on localhost and through ngrok).
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path && Storage::disk('public')->exists($this->avatar_path)
            ? asset('storage/'.$this->avatar_path)
            : null;
    }

    /**
     * Up to two initials for the avatar placeholder: "Aisyah Rahman" → "AR".
     */
    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->join('');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Number of this customer's Cash on Delivery orders whose delivery failed. Uses a preloaded
     * failed_cod_count (see Admin\OrderController) when present, so lists don't query per row.
     */
    public function failedCodCount(): int
    {
        return (int) ($this->getAttribute('failed_cod_count')
            ?? $this->orders()->failedCodDelivery()->count());
    }

    /**
     * Whether this customer has reached the failed-delivery limit and must pay online.
     */
    public function isCodBlocked(): bool
    {
        return $this->failedCodCount() >= config('shop.cod.max_failed_deliveries');
    }
}
