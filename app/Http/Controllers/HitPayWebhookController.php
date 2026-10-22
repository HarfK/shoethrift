<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Payments\HitPayClient;
use App\Services\Payments\SyncHitPayPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HitPay event webhook (register in the dashboard: Developers → Webhook Endpoints,
 * event payment_request.completed; `php artisan hitpay:webhook-url` prints the URL).
 * The primary way an online order becomes "paid".
 *
 * 1. Verify Hitpay-Signature = HMAC-SHA256(raw body, webhook salt); otherwise 401.
 * 2. Re-fetch the payment request from HitPay's API and trust only that response (the docs
 *    have no reliable completed-payload example, and this also guards against stale events).
 * 3. Hand it to SyncHitPayPayment, the same code the order page uses: it requires status
 *    completed + a succeeded payment + matching amount/currency, and is idempotent.
 *
 * Responds 200 for anything it deliberately ignores, so HitPay doesn't keep retrying.
 */
class HitPayWebhookController extends Controller
{
    public function __invoke(Request $request, HitPayClient $hitPay, SyncHitPayPayment $sync): Response
    {
        $rawBody = $request->getContent();

        if (! $hitPay->verifySignature($rawBody, $request->header('Hitpay-Signature'))) {
            Log::warning('HitPay webhook rejected: invalid signature', ['ip' => $request->ip()]);

            return response('Invalid signature', 401);
        }

        if (strtolower((string) $request->header('Hitpay-Event-Object')) !== 'payment_request') {
            return response('Ignored: not a payment request event', 200);
        }

        $paymentRequestId = json_decode($rawBody, true)['id'] ?? null;
        if (! is_string($paymentRequestId) || $paymentRequestId === '') {
            return response('Ignored: no payment request id', 200);
        }

        try {
            $paymentRequest = $hitPay->getPaymentRequest($paymentRequestId);
        } catch (Throwable $e) {
            Log::error('HitPay webhook: could not confirm payment request', ['id' => $paymentRequestId, 'error' => $e->getMessage()]);

            return response('Could not confirm payment, retry later', 503);
        }

        $order = Order::query()
            ->where('order_number', (string) ($paymentRequest['reference_number'] ?? ''))
            ->where('payment_method', PaymentMethod::Online)
            ->first();

        if (! $order) {
            Log::warning('HitPay webhook: no matching online order', ['id' => $paymentRequestId, 'reference' => $paymentRequest['reference_number'] ?? null]);

            return response('Ignored: unknown order', 200);
        }

        $result = $sync->apply($order, $paymentRequest);

        return in_array($result, ['paid', 'already_paid', 'late'], true)
            ? response("OK ({$result})", 200)
            : response("Ignored: not completed or amount mismatch ({$result})", 200);
    }
}
