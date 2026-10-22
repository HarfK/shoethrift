<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the HitPay API, following https://docs.hitpayapp.com:
 *
 * - Create payment request: POST /v1/payment-requests (form-encoded), header X-BUSINESS-API-KEY.
 * - Get payment status:     GET  /v1/payment-requests/{id}.
 * - Webhooks: Hitpay-Signature = HMAC-SHA256 of the raw JSON body, keyed with the webhook
 *   endpoint's salt.
 */
class HitPayClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.hitpay.api_url'), '/'))
            ->withHeaders([
                'X-BUSINESS-API-KEY' => (string) config('services.hitpay.api_key'),
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Create a payment request for an order. payment_methods is deliberately omitted so HitPay
     * offers whatever the account has enabled (e.g. FPX/TnG on a Malaysian live account).
     *
     * @return array{id: string, url: string}
     *
     * @throws RequestException
     */
    public function createPaymentRequest(Order $order): array
    {
        $order->loadMissing('user');

        $response = $this->http()->asForm()->post('/v1/payment-requests', [
            'amount' => number_format((float) $order->total, 2, '.', ''),
            'currency' => self::currency(),
            'reference_number' => $order->order_number,
            'purpose' => "ShoeThrift order {$order->order_number}",
            'name' => $order->shipping_name ?? $order->user->name,
            'email' => $order->user->email,
            'redirect_url' => route('account.orders.show', $order->order_number),
            'expires_after' => config('services.hitpay.payment_window_minutes').' mins',
        ])->throw();

        return ['id' => (string) $response->json('id'), 'url' => (string) $response->json('url')];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getPaymentRequest(string $id): array
    {
        return $this->http()->get('/v1/payment-requests/'.rawurlencode($id))->throw()->json();
    }

    /**
     * Verify a webhook's Hitpay-Signature against the raw body (constant-time comparison).
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $salt = (string) config('services.hitpay.salt');

        if ($salt === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $salt), $signature);
    }

    /**
     * The payment-request id of the succeeded payment if HitPay reports this request as fully
     * paid for exactly the order's amount and currency; null otherwise.
     *
     * @param  array<string, mixed>  $paymentRequest  as returned by getPaymentRequest()
     */
    public static function succeededPaymentId(array $paymentRequest, Order $order): ?string
    {
        if (($paymentRequest['status'] ?? null) !== 'completed'
            || ($paymentRequest['reference_number'] ?? null) !== $order->order_number
            || strtoupper((string) ($paymentRequest['currency'] ?? '')) !== self::currency()
            || self::toSen($paymentRequest['amount'] ?? 0) !== self::toSen($order->total)) {
            return null;
        }

        foreach ($paymentRequest['payments'] ?? [] as $payment) {
            if (in_array($payment['status'] ?? null, ['succeeded', 'succeeded_manually'], true)) {
                return (string) ($payment['id'] ?? '');
            }
        }

        return null;
    }

    public static function currency(): string
    {
        return strtoupper((string) config('services.hitpay.currency', 'MYR'));
    }

    private static function toSen(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
