<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Cart\ProductWatchers;
use App\Services\Cart\ReservationException;
use App\Services\Cart\Reservations;
use App\Services\ShoppingCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The cart (login required). Adding a pair reserves it for Reservations::holdMinutes(); the
 * locking and limits live in App\Services\Cart\Reservations.
 */
class CartController extends Controller
{
    public function index(ShoppingCart $cart): View
    {
        $cart->reconcile();
        $lines = $cart->lines();

        return view('cart.index', [
            'lines' => $lines,
            'subtotal' => $lines->sum(fn ($line) => $line->total()),
            'maxItems' => Reservations::maxItems(),
        ]);
    }

    public function store(Request $request, ShoppingCart $cart): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $line = $cart->add((int) $data['product_id'], $data['quantity'] ?? 1);
        } catch (ReservationException $e) {
            return back()->with('error', $e->getMessage());
        }

        $product = $line->product;

        return back()
            ->with('success', "Added {$product->name} ({$product->size_label}) to your cart. It's reserved for you until {$line->reserved_until->shopTime()}.")
            ->with('cart_added', true);
    }

    public function update(Request $request, ShoppingCart $cart, int $productId): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $line = $cart->update($productId, $data['quantity']);
        } catch (ReservationException $e) {
            // The pair can't be held any more (sold, withdrawn, or a limit): let the cart explain.
            $cart->reconcile();

            return redirect()->route('cart.index')->with('error', $e->getMessage());
        }

        return redirect()->route('cart.index')->with('success', $line->quantity < $data['quantity']
            ? "Only {$line->quantity} ".str('pair')->plural($line->quantity).' available, so the quantity was set to '.$line->quantity.'.'
            : 'Your cart was updated.');
    }

    public function destroy(ShoppingCart $cart, int $productId): RedirectResponse
    {
        $cart->remove($productId);
        $name = Product::whereKey($productId)->value('name');

        return redirect()->route('cart.index')
            ->with('success', $name ? "Removed {$name} from your cart." : 'Item removed from your cart.');
    }

    /**
     * "Notify me when it's free" on a pair reserved by someone else (toggle).
     */
    public function watch(Request $request, string $slug): RedirectResponse
    {
        $product = Product::visible()->where('slug', $slug)->firstOrFail();
        $userId = $request->user()->id;

        if (ProductWatchers::isWatching($userId, $product->id)) {
            ProductWatchers::unwatch($userId, $product);

            return back()->with('success', "OK, we won't email you about {$product->name}.");
        }

        ProductWatchers::watch($userId, $product);

        return back()->with('success', "We'll email {$request->user()->email} once {$product->name} is free again.");
    }
}
