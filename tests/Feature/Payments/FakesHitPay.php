<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * A fake HitPay API for tests: POST /v1/payment-requests creates "pr-N";
 * GET /v1/payment-requests/{id} returns $this->remote[$id] (404 if unknown, 500 when $hitPayDown).
 */
trait FakesHitPay
{
    private const HITPAY_API = 'https://api.sandbox.hit-pay.com/v1/payment-requests';

    /** @var array<string, array<string, mixed>> */
    private array $remote = [];

    private bool $hitPayDown = false;

    private function fakeHitPay(): void
    {
        Http::fake(function (HttpRequest $request) {
            if (! str_starts_with($request->url(), self::HITPAY_API)) {
                return null;
            }
            if ($this->hitPayDown) {
                return Http::response(['message' => 'Server error'], 500);
            }
            if ($request->method() === 'POST') {
                $n = count($this->remote) + 1;

                return Http::response(['id' => "pr-{$n}", 'url' => "https://checkout.sandbox.hit-pay.com/pay/{$n}", 'status' => 'pending'], 201);
            }

            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return isset($this->remote[$id]) ? Http::response($this->remote[$id]) : Http::response(['message' => 'Not found'], 404);
        });
    }

    /**
     * Set what HitPay's GET /v1/payment-requests/{id} returns for this order.
     */
    private function hitPayHas(Order $order, string $status, string $id = 'pr-1', ?string $amount = null, string $paymentStatus = 'succeeded'): void
    {
        $amount ??= number_format((float) $order->total, 2, '.', '');

        $this->remote[$id] = [
            'id' => $id,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'MYR',
            'reference_number' => $order->order_number,
            'payments' => $status === 'completed' ? [['id' => "pay-{$id}", 'status' => $paymentStatus, 'amount' => $amount, 'currency' => 'myr']] : [],
        ];
    }

    private function hitPayStatusChecks(string $id = 'pr-1'): int
    {
        return count(Http::recorded(fn (HttpRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/'.$id)));
    }

    private function signedWebhook(string $paymentRequestId)
    {
        $raw = json_encode(['id' => $paymentRequestId]);

        return $this->call('POST', '/webhooks/hitpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HITPAY_SIGNATURE' => hash_hmac('sha256', $raw, (string) config('services.hitpay.salt')),
            'HTTP_HITPAY_EVENT_OBJECT' => 'payment_request',
            'HTTP_HITPAY_EVENT_TYPE' => 'completed',
        ], $raw);
    }
}
