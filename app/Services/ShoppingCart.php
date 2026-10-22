<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The shopper's cart. Guests keep it in the session; logged-in users in the carts/cart_items
 * tables. The guest cart is merged into the saved cart on login (see MergeGuestCart).
 *
 * Bound as a scoped singleton, so notices collected while reconciling during a request are
 * shown by the shop layout in that same request.
 *
 * Adding to the cart does not reserve stock: whoever checks out first gets the pair.
 */
class ShoppingCart
{
    public const SESSION_KEY = 'cart';

    /** @var list<string> */
    private array $notices = [];

    /**
     * @return array<int, int> product id => quantity
     */
    private function raw(): array
    {
        $items = ($user = $this->user())
            ? ($user->cart?->items()->pluck('quantity', 'product_id')->all() ?? [])
            : session(self::SESSION_KEY, []);

        return array_map('intval', $items);
    }

    /**
     * Set a product's quantity; 0 removes it.
     */
    private function put(int $productId, int $quantity): void
    {
        if ($user = $this->user()) {
            $cart = $user->cart()->firstOrCreate();

            $quantity > 0
                ? $cart->items()->updateOrCreate(['product_id' => $productId], ['quantity' => $quantity])
                : $cart->items()->where('product_id', $productId)->delete();

            $user->setRelation('cart', $cart);

            return;
        }

        $items = session(self::SESSION_KEY, []);

        if ($quantity > 0) {
            $items[$productId] = $quantity;
        } else {
            unset($items[$productId]);
        }

        session()->put(self::SESSION_KEY, $items);
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Raw contents for checkout, which re-reads (and locks) the products itself.
     *
     * @return array<int, int> product id => quantity
     */
    public function quantities(): array
    {
        return $this->raw();
    }

    /**
     * Empty the cart (after an order is placed).
     */
    public function clear(): void
    {
        if ($user = $this->user()) {
            $user->cart?->items()->delete();

            return;
        }

        session()->forget(self::SESSION_KEY);
    }

    public function quantityOf(Product $product): int
    {
        return $this->raw()[$product->id] ?? 0;
    }

    /**
     * Total number of pairs in the cart.
     */
    public function count(): int
    {
        return array_sum($this->raw());
    }

    /**
     * Add pairs, never exceeding the product's stock. Returns the new quantity in the cart.
     */
    public function add(Product $product, int $quantity = 1): int
    {
        $newQuantity = min($this->quantityOf($product) + max($quantity, 1), $product->stock);
        $this->put($product->id, $newQuantity);

        return $newQuantity;
    }

    /**
     * Set the quantity, clamped to 1..stock. Returns the quantity actually set.
     */
    public function update(Product $product, int $quantity): int
    {
        $newQuantity = max(1, min($quantity, $product->stock));
        $this->put($product->id, $newQuantity);

        return $newQuantity;
    }

    public function remove(int $productId): void
    {
        $this->put($productId, 0);
    }

    /**
     * Drop items that are no longer available and clamp quantities to current stock,
     * recording a notice for the customer for each change.
     */
    public function reconcile(): void
    {
        $raw = $this->raw();

        if ($raw === []) {
            return;
        }

        $products = Product::whereIn('id', array_keys($raw))->get()->keyBy('id');

        foreach ($raw as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product?->isAvailable()) {
                $this->put($productId, 0);
                $this->notices[] = $product
                    ? "{$product->name} ({$product->size_label}) is no longer available and was removed from your cart."
                    : 'An item in your cart is no longer available and was removed.';

                continue;
            }

            if ($quantity > $product->stock) {
                $this->put($productId, $product->stock);
                $this->notices[] = "Only {$product->stock} ".str('pair')->plural($product->stock)
                    ." of {$product->name} ({$product->size_label}) ".($product->stock === 1 ? 'is' : 'are').' left, so we updated your cart.';
            }
        }
    }

    /**
     * Cart contents with products loaded, in the order they were added. Call reconcile() first.
     *
     * @return Collection<int, CartLine>
     */
    public function lines(): Collection
    {
        $raw = $this->raw();
        $products = Product::with(['primaryImage', 'category'])->whereIn('id', array_keys($raw))->get()->keyBy('id');

        return collect($raw)
            ->filter(fn (int $quantity, int $productId) => $products->has($productId))
            ->map(fn (int $quantity, int $productId) => new CartLine($products->get($productId), $quantity))
            ->values();
    }

    /**
     * Take (and clear) the notices produced by reconcile(), so each is shown exactly once
     * even if this instance outlives the request (tests, Octane).
     *
     * @return list<string>
     */
    public function pullNotices(): array
    {
        [$notices, $this->notices] = [$this->notices, []];

        return $notices;
    }

    /**
     * Move the session (guest) cart into the user's saved cart. A product in both keeps the
     * larger quantity (never doubled), capped at current stock; unavailable items are dropped.
     */
    public function mergeGuestCartInto(User $user): void
    {
        $guestItems = session()->pull(self::SESSION_KEY, []);

        if ($guestItems === []) {
            return;
        }

        $cart = $user->cart()->firstOrCreate();
        $saved = $cart->items()->pluck('quantity', 'product_id');
        $products = Product::whereIn('id', array_keys($guestItems))->get()->keyBy('id');

        foreach ($guestItems as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product?->isAvailable()) {
                continue;
            }

            $cart->items()->updateOrCreate(
                ['product_id' => $productId],
                ['quantity' => min(max($quantity, $saved->get($productId, 0)), $product->stock)],
            );
        }

        $user->setRelation('cart', $cart);
    }
}
