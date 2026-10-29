<?php

namespace App\Services\Cart;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartRelease;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Timed cart holds. Each cart line (cart_items) IS the reservation of its pairs until
 * `reserved_until`; nobody else can add or buy those pairs meanwhile.
 *
 * Race safety: reserving locks the product row (SELECT … FOR UPDATE), so two customers adding the
 * same one-of-a-kind pair queue on the lock and the second sees the first hold. PlaceOrder locks the
 * same rows and refuses pairs held by someone else.
 *
 * Correctness never depends on cleanup: every availability check counts only holds with
 * reserved_until > now. releaseExpired() (scheduled every minute AND run lazily on cart, shop,
 * product and checkout requests) just removes expired lines and records why, exactly once.
 *
 * Holds are never extended: re-adding, changing quantity, viewing the cart or opening checkout
 * keep the original expiry.
 */
class Reservations
{
    public static function holdMinutes(): int
    {
        return max(1, (int) config('shop.cart.hold_minutes'));
    }

    public static function maxItems(): int
    {
        return max(1, (int) config('shop.cart.max_items'));
    }

    /**
     * Active holds (not expired), optionally excluding one customer's own.
     *
     * @return Builder<CartItem>
     */
    public static function activeHolds(?int $exceptUserId = null): Builder
    {
        return CartItem::query()
            ->where('cart_items.reserved_until', '>', now())
            ->when($exceptUserId, fn (Builder $query) => $query->whereHas('cart', fn (Builder $query) => $query->where('user_id', '!=', $exceptUserId)));
    }

    /**
     * Pairs of this product held by other customers right now.
     */
    public static function heldByOthers(int $productId, ?int $exceptUserId): int
    {
        return (int) self::activeHolds($exceptUserId)->where('product_id', $productId)->sum('quantity');
    }

    /**
     * When the earliest hold by someone else on this product ends (it may become free then).
     */
    public static function nextFreeAt(int $productId, ?int $exceptUserId): ?Carbon
    {
        $at = self::activeHolds($exceptUserId)->where('product_id', $productId)->min('reserved_until');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * Reserve $quantity more pairs for $user (new line: a fresh hold; existing line: same expiry).
     *
     * @throws ReservationException with a customer-facing message
     */
    public function reserve(User $user, int $productId, int $quantity = 1): CartItem
    {
        return $this->setQuantity($user, $productId, $quantity, add: true);
    }

    /**
     * Change the quantity of an existing line, never beyond what's free. Keeps the expiry.
     *
     * @throws ReservationException
     */
    public function update(User $user, int $productId, int $quantity): CartItem
    {
        return $this->setQuantity($user, $productId, $quantity, add: false);
    }

    /**
     * The customer removed the line themselves (no release record), and watchers may be told.
     */
    public function remove(User $user, int $productId): void
    {
        $deleted = CartItem::query()
            ->whereHas('cart', fn (Builder $query) => $query->where('user_id', $user->id))
            ->where('product_id', $productId)
            ->delete();

        if ($deleted) {
            ProductWatchers::notifyIfFree([$productId]);
        }
    }

    /**
     * @throws ReservationException
     */
    private function setQuantity(User $user, int $productId, int $quantity, bool $add): CartItem
    {
        return DB::transaction(function () use ($user, $productId, $quantity, $add) {
            // Lock the pair first: every hold and purchase of it queues here.
            $product = Product::query()->lockForUpdate()->find($productId);
            $this->releaseExpired($productId);

            if (! $product?->isAvailable()) {
                throw new ReservationException($product && $product->status !== ProductStatus::Hidden
                    ? 'Sorry, someone else just bought this pair.'
                    : 'Sorry, this pair is no longer for sale.');
            }

            $cart = Cart::firstOrCreate(['user_id' => $user->id]);
            $line = $cart->items()->where('product_id', $productId)->lockForUpdate()->first();
            if (! $add && ! $line) {
                throw new ReservationException('Your reservation for this pair ended, so it is no longer in your cart.');
            }
            $current = $line && $line->reserved_until?->isFuture() ? $line->quantity : 0;
            $wanted = $add ? $current + max(1, $quantity) : max(1, $quantity);

            $free = $product->stock - self::heldByOthers($productId, $user->id);
            if ($free <= 0) {
                $nextFreeAt = self::nextFreeAt($productId, $user->id);

                throw new ReservationException('Reserved by another shopper. It may be available again'
                    .($nextFreeAt ? ' around '.$nextFreeAt->shopTime() : ' soon').'.');
            }
            if ($wanted > $free) {
                if (! $add) {
                    $wanted = $free; // clamp a quantity change
                } else {
                    throw new ReservationException($current > 0
                        ? "Only {$free} ".str('pair')->plural($free).' available, and '.($current === 1 ? "it's" : "they're").' already in your cart.'
                        : "Only {$free} ".str('pair')->plural($free).' available right now.');
                }
            }

            $otherPairs = (int) $cart->items()->where('product_id', '!=', $productId)->where('reserved_until', '>', now())->sum('quantity');
            if ($otherPairs + $wanted > self::maxItems()) {
                throw new ReservationException('Your cart can hold up to '.self::maxItems().' pairs at a time. Check out or remove a pair first.');
            }

            $line ??= $cart->items()->make(['product_id' => $productId]);
            $line->quantity = $wanted;
            // A new (or expired) line gets a fresh hold; an active one keeps its expiry.
            if (! $line->reserved_until?->isFuture()) {
                $line->reserved_until = now()->addMinutes(self::holdMinutes());
            }
            $line->save();

            return $line;
        });
    }

    /**
     * Remove expired holds (all, or one product's) and record why, exactly once: the lines are
     * selected FOR UPDATE and deleted in the same transaction, so a concurrent run (scheduler and
     * a page request) finds nothing left to release.
     *
     * @return int number of lines released
     */
    public function releaseExpired(?int $productId = null): int
    {
        $released = DB::transaction(function () use ($productId) {
            $lines = CartItem::query()
                ->where('reserved_until', '<=', now())
                ->when($productId, fn (Builder $query) => $query->where('product_id', $productId))
                ->with(['cart:id,user_id', 'product'])
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                $this->recordRelease($line, $this->reasonFor($line->product));
                $line->delete();
            }

            return $lines;
        });

        if ($released->isNotEmpty()) {
            ProductWatchers::notifyIfFree($released->pluck('product_id')->unique()->all());
        }

        return $released->count();
    }

    /**
     * End one customer's lines whose pairs can no longer be held (sold, hidden or deleted while
     * in the cart), recording the reason. Returns the released product ids.
     *
     * @return list<int>
     */
    public function releaseUnavailableFor(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $lines = CartItem::query()
                ->whereHas('cart', fn (Builder $query) => $query->where('user_id', $user->id))
                ->with(['cart:id,user_id', 'product'])
                ->lockForUpdate()
                ->get()
                ->reject(fn (CartItem $line) => $line->product?->isAvailable());

            foreach ($lines as $line) {
                $this->recordRelease($line, $this->reasonFor($line->product, expired: false));
                $line->delete();
            }

            return $lines->pluck('product_id')->all();
        });
    }

    /**
     * A product is being deleted: tell everyone who had it in their cart.
     */
    public function releaseForDeletedProduct(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $lines = CartItem::query()->where('product_id', $product->id)->with('cart:id,user_id')->lockForUpdate()->get();

            foreach ($lines as $line) {
                $line->setRelation('product', $product);
                $this->recordRelease($line, CartRelease::NO_LONGER_FOR_SALE, keepProductId: false);
                $line->delete();
            }
        });
    }

    /**
     * Why a hold ended: if the pair is gone it was bought (or withdrawn); otherwise it simply expired.
     */
    private function reasonFor(?Product $product, bool $expired = true): string
    {
        return match (true) {
            $product === null, $product->status === ProductStatus::Hidden => CartRelease::NO_LONGER_FOR_SALE,
            $product->status === ProductStatus::Sold, $product->stock <= 0 => CartRelease::BOUGHT_BY_OTHER,
            default => $expired ? CartRelease::EXPIRED : CartRelease::NO_LONGER_FOR_SALE,
        };
    }

    private function recordRelease(CartItem $line, string $reason, bool $keepProductId = true): void
    {
        if (! $line->cart) {
            return;
        }

        CartRelease::create([
            'user_id' => $line->cart->user_id,
            'product_id' => $keepProductId ? $line->product_id : null,
            'product_name' => $line->product ? "{$line->product->name} ({$line->product->size_label})" : 'An item',
            'reason' => $reason,
            'quantity' => $line->quantity,
            'released_at' => now(),
        ]);
    }
}
