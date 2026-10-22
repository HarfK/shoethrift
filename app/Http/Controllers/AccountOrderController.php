<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\StartHitPayPayment;
use App\Services\Payments\SyncHitPayPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My orders" for the logged-in customer. Only ever queries through $user->orders(),
 * so other customers' orders 404.
 */
class AccountOrderController extends Controller
{
    private const PER_PAGE = 10;

    /** The status page refreshes every REFRESH_SECONDS while pending, up to MAX_AUTO_CHECKS times (2 minutes). */
    public const REFRESH_SECONDS = 5;

    public const MAX_AUTO_CHECKS = 24;

    public function index(Request $request): View
    {
        return view('account.orders.index', [
            'orders' => $request->user()->orders()
                ->withCount('items')
                ->latest()
                ->latest('id')
                ->paginate(self::PER_PAGE),
        ]);
    }

    /**
     * The order status page, which is also where HitPay sends the customer back. Each load of an
     * unpaid online order asks HitPay's API for the real status (throttled). The return URL's own
     * query string (status etc.) is never trusted. `?check=N` counts auto-refreshes so they stop
     * after MAX_AUTO_CHECKS.
     */
    public function show(Request $request, string $orderNumber, SyncHitPayPayment $sync): View
    {
        $order = $this->ownOrder($request, $orderNumber);
        $syncResult = $sync->refresh($order, throttle: true);

        $check = max(0, (int) $request->query('check', 0));
        $awaiting = $order->isAwaitingOnlinePayment();

        return view('account.orders.show', [
            'order' => $order->load('items.product.primaryImage'),
            'checkUnavailable' => $syncResult === 'unreachable',
            'autoRefresh' => $awaiting && $check < self::MAX_AUTO_CHECKS,
            'nextCheckUrl' => route('account.orders.show', ['orderNumber' => $order->order_number, 'check' => $check + 1]),
            'stillWaiting' => $awaiting && $check >= self::MAX_AUTO_CHECKS,
        ]);
    }

    /**
     * "Check again": ask HitPay now (not throttled), then show the result.
     */
    public function check(Request $request, string $orderNumber, SyncHitPayPayment $sync): RedirectResponse
    {
        $order = $this->ownOrder($request, $orderNumber);

        $message = match ($sync->refresh($order)) {
            'paid', 'already_paid' => ['success', 'Payment received. Thank you!'],
            'failed' => ['error', "Your payment didn't go through, so the order was closed and the pairs went back to the shop."],
            'unreachable' => ['error', "We couldn't reach the payment provider. Please try again in a moment."],
            'pending' => ['success', "HitPay hasn't received your payment yet. If you've just paid, give it a moment."],
            default => null,
        };

        $redirect = redirect()->route('account.orders.show', ['orderNumber' => $orderNumber, 'check' => self::MAX_AUTO_CHECKS]);

        return $message ? $redirect->with(...$message) : $redirect;
    }

    /**
     * "Pay now" / "Try again": send the customer (back) to HitPay for an unpaid online order.
     */
    public function pay(Request $request, string $orderNumber, StartHitPayPayment $startPayment): RedirectResponse
    {
        $order = $this->ownOrder($request, $orderNumber);

        if (! $order->isAwaitingOnlinePayment() || $order->payment_expires_at?->isPast()) {
            return redirect()->route('account.orders.show', $orderNumber)
                ->with('error', 'This order can no longer be paid online.');
        }

        $paymentUrl = $startPayment->handle($order);

        return $paymentUrl
            ? redirect()->away($paymentUrl)
            : redirect()->route('account.orders.show', $orderNumber)
                ->with('error', "We couldn't reach the payment provider. Please try again in a moment.");
    }

    /**
     * Printable receipt (browser "Print / Save as PDF"), for paid orders only.
     */
    public function receipt(Request $request, string $orderNumber): View|RedirectResponse
    {
        $order = $this->ownOrder($request, $orderNumber);

        if (! $order->hasReceipt()) {
            return redirect()->route('account.orders.show', $orderNumber)
                ->with('error', 'A receipt is available once the order is paid.');
        }

        return view('account.orders.receipt', ['order' => $order->load(['user', 'items.product'])]);
    }

    /**
     * LOCAL TEST TOOL ONLY: pretend HitPay confirmed the payment. 404 unless APP_ENV=local.
     * Goes through the same locked, idempotent Order::recordGatewayPayment() as real payments,
     * and the payment id is prefixed SIMULATED- so it's never mistaken for a real one.
     */
    public function simulatePayment(Request $request, string $orderNumber): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $order = $this->ownOrder($request, $orderNumber);

        if (! $order->isAwaitingOnlinePayment()) {
            return redirect()->route('account.orders.show', $orderNumber)
                ->with('error', 'Only an unpaid online order can be simulated as paid.');
        }

        $order->recordGatewayPayment($order->gateway_reference ?? 'SIMULATED', 'SIMULATED-'.now()->format('YmdHis'));

        return redirect()->route('account.orders.show', $orderNumber)
            ->with('success', 'Test payment simulated (local only). No real money moved.');
    }

    private function ownOrder(Request $request, string $orderNumber): Order
    {
        return $request->user()->orders()->where('order_number', $orderNumber)->firstOrFail();
    }
}
