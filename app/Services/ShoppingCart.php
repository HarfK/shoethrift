<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\CartRelease;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\Reservations;
use App\Services\Recommendations\SimilarProducts;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The logged-in customer's cart (carts / cart_items). Login is required to add: each line is a
 * timed reservation of its pairs (see App\Services\Cart\Reservations, which does all locking).
 *
 * Bound as a scoped singleton, so notices collected while reconciling during a request are
 * shown by the shop layout in that same request. Notices about holds that ended (expired, bought
 * by someone else, no longer for sale) come from cart_releases, so they're shown even if the
 * scheduler removed the line while the customer was away, and then marked as seen.
 */
class ShoppingCart
{
    /** Similar available pairs suggested under a "removed from your cart" notice. */
    public const SIMILAR_LINKS = 3;

    /** @var list<CartNotice> */
    private array $notices = [];

    public function __construct(private readonly Reservations $reservations) {}

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The customer's lines (including expired ones not released yet).
     *
     * @return EloquentCollection<int, CartItem>
     */
    private function items(): EloquentCollection
    {
        $user = $this->user();

        return $user
            ? CartItem::query()->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))->orderBy('id')->get()
            : new EloquentCollection;
    }

    /**
     * Raw contents for checkout, which re-reads and locks the products itself and refuses
     * expired holds (PlaceOrder).
     *
     * @return array<int, int> product id => quantity
     */
    public function quantities(): array
    {
        return $this->items()->mapWithKeys(fn (CartItem $item) => [$item->product_id => $item->quantity])->all();
    }

    /**
     * Empty the cart (after an order is placed).
     */
    public function clear(): void
    {
        $this->user()?->cart?->items()->delete();
    }

    /**
     * Pairs of this product the customer holds right now.
     */
    public function quantityOf(Product $product): int
    {
        return (int) $this->items()->where('product_id', $product->id)->first(fn (CartItem $item) => $item->reserved_until?->isFuture())?->quantity;
    }

    /**
     * When the customer's hold on this product ends (null if they don't hold it).
     */
    public function heldUntil(Product $product): ?Carbon
    {
        $until = $this->items()->firstWhere('product_id', $product->id)?->reserved_until;

        return $until?->isFuture() ? $until : null;
    }

    /**
     * Total number of pairs currently held.
     */
    public function count(): int
    {
        return (int) $this->items()->filter(fn (CartItem $item) => $item->reserved_until?->isFuture())->sum('quantity');
    }

    /**
     * Reserve pairs for the logged-in customer.
     *
     * @throws Cart\ReservationException
     */
    public function add(int $productId, int $quantity = 1): CartItem
    {
        return $this->reservations->reserve($this->requireUser(), $productId, $quantity)->load('product');
    }

    /**
     * Change the quantity, clamped to what's free. Keeps the hold's expiry.
     *
     * @throws Cart\ReservationException
     */
    public function update(int $productId, int $quantity): CartItem
    {
        return $this->reservations->update($this->requireUser(), $productId, $quantity)->load('product');
    }

    public function remove(int $productId): void
    {
        $this->reservations->remove($this->requireUser(), $productId);
    }

    /**
     * Release expired holds (lazily, for everyone), end this customer's lines whose pairs are gone,
     * clamp quantities to stock, and turn the customer's unseen releases into notices.
     */
    public function reconcile(): void
    {
        // Lazy expiry for everyone (also for guests' page views), so holds end even without the scheduler.
        $this->reservations->releaseExpired();

        $user = $this->user();
        if (! $user) {
            return;
        }

        $this->reservations->releaseUnavailableFor($user);

        foreach ($this->items()->load('product') as $item) {
            if ($item->product && $item->quantity > $item->product->stock) {
                $item->update(['quantity' => $item->product->stock]);
                $this->notices[] = new CartNotice("Only {$item->product->stock} ".str('pair')->plural($item->product->stock)
                    ." of {$item->product->name} ({$item->product->size_label}) ".($item->product->stock === 1 ? 'is' : 'are').' left, so we updated your cart.');
            }
        }

        $releases = CartRelease::query()->where('user_id', $user->id)->whereNull('notified_at')->with('product')->orderBy('id')->get();
        foreach ($releases as $release) {
            $this->notices[] = $this->noticeFor($release, $user);
        }
        if ($releases->isNotEmpty()) {
            CartRelease::query()->whereIn('id', $releases->pluck('id'))->update(['notified_at' => now()]);
        }
    }

    /**
     * The message for a hold that ended, with similar available pairs to look at instead.
     */
    private function noticeFor(CartRelease $release, User $user): CartNotice
    {
        $product = $release->product;

        // Sold, but to this same customer (e.g. in another tab): it's in their own order.
        if ($product && $release->reason === CartRelease::BOUGHT_BY_OTHER) {
            $ownOrder = $user->orders()
                ->whereIn('status', [OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered])
                ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
                ->latest('id')
                ->first();

            if ($ownOrder) {
                return new CartNotice(
                    "{$release->product_name} is already in your order {$ownOrder->order_number}"
                        .($ownOrder->isAwaitingOnlinePayment() ? ', waiting for payment' : '').', so we removed it from your cart.',
                    route('account.orders.show', $ownOrder->order_number),
                    'View order',
                );
            }
        }

        $similar = $product
            ? app(SimilarProducts::class)->for($product, self::SIMILAR_LINKS)
                ->map(fn (Product $p) => ['url' => route('shop.show', $p->slug), 'label' => "{$p->name} ({$p->size_label})"])
                ->all()
            : [];

        return new CartNotice(
            $release->message(),
            $product ? route('shop.index', ['size' => Product::formatSize($product->size)]) : route('shop.index'),
            $product ? "See other {$product->size_label} shoes" : 'Browse the shop',
            $similar,
        );
    }

    /**
     * Held lines with products loaded, in the order they were added. Call reconcile() first.
     *
     * @return Collection<int, CartLine>
     */
    public function lines(): Collection
    {
        return $this->items()
            ->filter(fn (CartItem $item) => $item->reserved_until?->isFuture())
            ->load(['product.primaryImage', 'product.category'])
            ->filter(fn (CartItem $item) => $item->product !== null)
            ->map(fn (CartItem $item) => new CartLine($item->product, $item->quantity, $item->reserved_until))
            ->values();
    }

    /**
     * Take (and clear) the notices produced by reconcile(), so each is shown exactly once
     * even if this instance outlives the request (tests, Octane).
     *
     * @return list<CartNotice>
     */
    public function pullNotices(): array
    {
        [$notices, $this->notices] = [$this->notices, []];

        return $notices;
    }

    private function requireUser(): User
    {
        return $this->user() ?? throw new \LogicException('Adding to the cart requires a logged-in customer.');
    }
}
