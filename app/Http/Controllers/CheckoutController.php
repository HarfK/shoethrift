<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Requests\AddressRequest;
use App\Models\Address;
use App\Models\Order;
use App\Services\CartNotice;
use App\Services\Checkout\CashOnDeliveryNotAllowedException;
use App\Services\Checkout\CashOnDeliveryPolicy;
use App\Services\Checkout\ItemsUnavailableException;
use App\Services\Checkout\PlaceOrder;
use App\Services\Checkout\PriceChangedException;
use App\Services\Checkout\ReservationExpiredException;
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

    public function create(Request $request, ShoppingCart $cart, CashOnDeliveryPolicy $codPolicy): View|RedirectResponse
    {
        $cart->reconcile();
        $lines = $cart->lines();

        if ($lines->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty. Add a pair before checking out.');
        }

        $subtotal = $lines->sum(fn ($line) => $line->total());
        $shippingFee = (float) config('shop.shipping_fee');

        $total = $subtotal + $shippingFee;

        return view('checkout.create', [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'total' => $total,
            'user' => $request->user(),
            'addresses' => $request->user()->addresses()->orderByDesc('is_default')->latest('updated_at')->get(),
            'states' => config('shop.states'),
            'codUnavailableReason' => $codPolicy->unavailableReason($request->user(), $total),
        ]);
    }

    public function store(Request $request, ShoppingCart $cart, PlaceOrder $placeOrder, StartHitPayPayment $startPayment): RedirectResponse
    {
        $user = $request->user();
        // address_id = one of the customer's saved addresses, or "new" (or absent) for the manual form.
        $useSaved = $request->filled('address_id') && $request->input('address_id') !== 'new';

        $data = $request->validate([
            'address_id' => $useSaved
                ? ['integer', Rule::exists('addresses', 'id')->where('user_id', $user->id)]
                : ['nullable'],
            ...($useSaved ? [] : collect(AddressRequest::fieldRules())->except('label')->all()),
            'save_address' => ['nullable', 'boolean'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'expected_total' => ['required', 'numeric'],
        ], [
            ...AddressRequest::fieldMessages(),
            'address_id.exists' => 'Choose one of your saved addresses.',
            'payment_method.required' => 'Choose how you want to pay.',
        ]);
        $paymentMethod = PaymentMethod::from($data['payment_method']);

        // The order keeps a snapshot; it never links to the address book.
        $address = $useSaved
            ? $user->addresses()->findOrFail((int) $data['address_id'])
            : new Address(collect($data)->only(['recipient_name', 'phone', 'line1', 'line2', 'postcode', 'city', 'state'])
                ->map(fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null)->all());

        $quantities = $cart->quantities();

        // A double-click's second submit arrives after the cart was emptied by the first.
        if ($quantities === []) {
            return redirect()->route('account.orders.index')->with('error', 'Your cart is empty. Your recent orders are below.');
        }

        try {
            $order = $placeOrder->handle(
                $request->user(),
                $quantities,
                $address->toShippingSnapshot(),
                (string) $data['expected_total'],
                $paymentMethod,
            );
        } catch (ItemsUnavailableException $e) {
            // Drop what's gone (and clamp quantities) so the customer reviews an accurate cart.
            // reconcile() explains each item (bought by someone else / in your own order / off sale).
            $cart->reconcile();
            $notices = $cart->pullNotices()
                ?: array_map(fn (string $item) => new CartNotice("{$item} is no longer available in that quantity."), array_values($e->items));

            return redirect()->route('cart.index')
                ->with('error', "Your order wasn't placed because your cart changed. Nothing was charged. Please review your cart and check out again.")
                ->with('cart_notices', $notices);
        } catch (ReservationExpiredException $e) {
            // Release the expired holds now so the cart explains each one ("Your reservation expired").
            $cart->reconcile();

            return redirect()->route('cart.index')
                ->with('error', "Your reservation ran out before the order was placed, so nothing was ordered or charged. Add the pair again if it's still free.")
                ->with('cart_notices', $cart->pullNotices());
        } catch (CashOnDeliveryNotAllowedException $e) {
            return redirect()->route('checkout.create')->withInput()->withErrors(['payment_method' => $e->getMessage()]);
        } catch (PriceChangedException $e) {
            return redirect()->route('checkout.create')->withInput()
                ->with('error', "Prices changed since you opened checkout. Your new total is RM {$e->newTotal}. Please review it before placing your order.");
        }

        $cart->clear();

        if (! $useSaved && $request->boolean('save_address') && $user->addresses()->count() < config('shop.max_addresses')) {
            $saved = $user->addresses()->create($address->getAttributes());
            if (! $user->defaultAddress()->exists()) {
                $saved->makeDefault();
            }
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
