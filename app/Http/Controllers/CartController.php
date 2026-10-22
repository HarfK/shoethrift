<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ShoppingCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function index(ShoppingCart $cart): View
    {
        $cart->reconcile();
        $lines = $cart->lines();

        return view('cart.index', [
            'lines' => $lines,
            'subtotal' => $lines->sum(fn ($line) => $line->total()),
        ]);
    }

    public function store(Request $request, ShoppingCart $cart): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $product = Product::find($data['product_id']);

        if (! $product?->isAvailable()) {
            return back()->with('error', 'Sorry, this pair is no longer available.');
        }

        if ($cart->quantityOf($product) >= $product->stock) {
            return back()->with('error', $product->stock === 1
                ? "Only 1 pair available. It's already in your cart."
                : "All {$product->stock} available pairs are already in your cart.");
        }

        $cart->add($product, $data['quantity'] ?? 1);

        return back()
            ->with('success', "Added {$product->name} ({$product->size_label}) to your cart.")
            ->with('cart_added', true);
    }

    public function update(Request $request, ShoppingCart $cart, int $productId): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $product = Product::find($productId);

        if (! $product?->isAvailable()) {
            $cart->remove($productId);

            return redirect()->route('cart.index')->with('error', 'Sorry, this pair is no longer available, so it was removed from your cart.');
        }

        $quantity = $cart->update($product, $data['quantity']);

        return redirect()->route('cart.index')->with('success', $quantity < $data['quantity']
            ? "Only {$product->stock} ".str('pair')->plural($product->stock)." available, so the quantity was set to {$quantity}."
            : 'Your cart was updated.');
    }

    public function destroy(ShoppingCart $cart, int $productId): RedirectResponse
    {
        $cart->remove($productId);
        $name = Product::whereKey($productId)->value('name');

        return redirect()->route('cart.index')
            ->with('success', $name ? "Removed {$name} from your cart." : 'Item removed from your cart.');
    }
}
