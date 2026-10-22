<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Checkout\ItemsUnavailableException;
use App\Services\Checkout\PlaceOrder;
use App\Services\Checkout\PriceChangedException;
use App\Services\Payments\StartHitPayPayment;
use App\Services\ShoppingCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /**
     * Malaysian phone numbers: 012-345 6789, 0123456789, +60 12-345 6789, 03-1234 5678.
     */
    public const PHONE_PATTERN = '/^(\+?60[\s-]?|0)1?[0-9][\s-]?[0-9]{3,4}[\s-]?[0-9]{4}$/';

    public function create(Request $request, ShoppingCart $cart): View|RedirectResponse
    {
        $cart->reconcile();
        $lines = $cart->lines();

        if ($lines->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty. Add a pair before checking out.');
        }

        $subtotal = $lines->sum(fn ($line) => $line->total());
        $shippingFee = (float) config('shop.shipping_fee');

        return view('checkout.create', [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'total' => $subtotal + $shippingFee,
            'user' => $request->user(),
        ]);
    }

    public function store(Request $request, ShoppingCart $cart, PlaceOrder $placeOrder, StartHitPayPayment $startPayment): RedirectResponse
    {
        $data = $request->validate([
            'shipping_name' => ['required', 'string', 'max:100'],
            'shipping_phone' => ['required', 'string', 'max:30', 'regex:'.self::PHONE_PATTERN],
            'shipping_address' => ['required', 'string', 'min:10', 'max:500'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'save_details' => ['nullable', 'boolean'],
            'expected_total' => ['required', 'numeric'],
        ], [
            'shipping_phone.regex' => 'Enter a Malaysian phone number, e.g. 012-345 6789.',
            'payment_method.required' => 'Choose how you want to pay.',
        ]);
        $paymentMethod = PaymentMethod::from($data['payment_method']);

        $quantities = $cart->quantities();

        // A double-click's second submit arrives after the cart was emptied by the first.
        if ($quantities === []) {
            return redirect()->route('account.orders.index')->with('error', 'Your cart is empty. Your recent orders are below.');
        }

        try {
            $order = $placeOrder->handle(
                $request->user(),
                $quantities,
                [
                    'shipping_name' => trim($data['shipping_name']),
                    'shipping_phone' => trim($data['shipping_phone']),
                    'shipping_address' => trim($data['shipping_address']),
                ],
                (string) $data['expected_total'],
                $paymentMethod,
            );
        } catch (ItemsUnavailableException $e) {
            // Drop what's gone (and clamp quantities) so the customer reviews an accurate cart.
            $cart->reconcile();
            $cart->pullNotices();

            $names = implode(', ', $e->items);

            return redirect()->route('cart.index')->with('error', count($e->items) === 1
                ? "Sorry, {$names} was just bought by someone else and was removed from your cart. Please review your cart before checking out."
                : "Sorry, these items were just bought by someone else and were removed from your cart: {$names}. Please review your cart before checking out.");
        } catch (PriceChangedException $e) {
            return redirect()->route('checkout.create')->withInput()
                ->with('error', "Prices changed since you opened checkout. Your new total is RM {$e->newTotal}. Please review it before placing your order.");
        }

        $cart->clear();

        if ($request->boolean('save_details')) {
            $request->user()->update([
                'phone' => $order->shipping_phone,
                'address' => $order->shipping_address,
            ]);
        }

        if ($paymentMethod === PaymentMethod::Online) {
            // The order (and its stock) is saved first; only then do we call HitPay.
            $paymentUrl = $startPayment->handle($order);

            return $paymentUrl
                ? redirect()->away($paymentUrl)
                : redirect()->route('account.orders.show', $order->order_number)
                    ->with('error', "Your order {$order->order_number} is saved, but we couldn't start the online payment. Please try again below.");
        }

        return redirect()->route('checkout.complete', $order->order_number);
    }

    public function complete(Request $request, string $orderNumber): View
    {
        return view('checkout.complete', [
            'order' => $this->ownOrder($request, $orderNumber),
        ]);
    }

    /**
     * The customer's own order, or 404 (never reveal that someone else's order exists).
     */
    private function ownOrder(Request $request, string $orderNumber): Order
    {
        return $request->user()->orders()
            ->where('order_number', $orderNumber)
            ->with('items.product.primaryImage')
            ->firstOrFail();
    }
}
