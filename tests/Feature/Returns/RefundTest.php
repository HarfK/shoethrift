<?php

namespace Tests\Feature\Returns;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\RefundMethod;
use App\Enums\ReturnStatus;
use App\Models\ReturnRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Recording refunds on approved returns: HitPay POST /v1/refund or manual, full or partial,
 * with restocking and protection against refunding twice.
 */
class RefundTest extends TestCase
{
    use BuildsReturnableOrders;
    use RefreshDatabase;

    private const REFUND_URL = 'https://api.sandbox.hit-pay.com/v1/refund';

    private ReturnRequest $return;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReturnableOrder();
        $this->return = $this->approvedReturn($this->order);
    }

    private function approvedReturn($order): ReturnRequest
    {
        $return = ReturnRequest::factory()->create(['order_id' => $order->id]);
        $return->items()->create(['order_item_id' => $order->items->first()->id, 'quantity' => 1]);
        $return->approve(null);

        return $return->fresh();
    }

    private function hitPayRefunds(string $amountRefunded = '300.00', string $status = 'succeeded'): void
    {
        Http::fake([self::REFUND_URL => Http::response([
            'id' => '9ef6957e-6b1b-4e08-8cde-d5f5c908418d',
            'payment_id' => 'pay-9a934d94',
            'amount_refunded' => (float) $amountRefunded,
            'total_amount' => 310,
            'currency' => 'myr',
            'status' => $status,
            'refunded_at' => '2026-10-27T03:00:00.000000Z',
        ])]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function refund(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.returns.refund', $this->return), [
            'amount' => '300.00',
            'method' => 'hitpay',
            'restock' => [$this->return->items->first()->id],
            ...$overrides,
        ]);
    }

    public function test_full_refund_through_hitpay(): void
    {
        $this->hitPayRefunds('310.00');

        $this->refund(['amount' => '310.00'])
            ->assertRedirect(route('admin.returns.show', $this->return))
            ->assertSessionHas('success', 'Refund of RM 310.00 recorded (Refunded through HitPay, ref 9ef6957e-6b1b-4e08-8cde-d5f5c908418d).');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::REFUND_URL
            && $request->method() === 'POST'
            && $request->hasHeader('X-BUSINESS-API-KEY')
            && $request['payment_id'] === 'pay-9a934d94'
            && (float) $request['amount'] === 310.0);

        $return = $this->return->fresh();
        $this->assertSame(ReturnStatus::Refunded, $return->status);
        $this->assertSame(RefundMethod::HitPay, $return->refund_method);
        $this->assertSame('310.00', $return->refund_amount);
        $this->assertNotNull($return->refunded_at);

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame('310.00', $order->refunded_amount);

        $this->assertSame(1, $this->dunk->fresh()->stock);
        $this->assertSame(ProductStatus::Available, $this->dunk->fresh()->status);
        $this->assertTrue($return->items->first()->fresh()->restocked);

        $this->actingAs($this->customer)->get(route('account.orders.show', $this->order->order_number))
            ->assertSee(['Refunded', 'RM 310.00 was refunded on'])
            ->assertDontSee('Request a return');
    }

    public function test_partial_refund_keeps_the_order_completed(): void
    {
        $this->hitPayRefunds('250.00');

        $this->refund(['amount' => '250.00'])->assertSessionHas('success');

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('250.00', $order->refunded_amount);
        $this->assertTrue($order->isPartiallyRefunded());
        $this->assertSame('Partially refunded', $order->statusLabel());

        $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertSee('Partially refunded');
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertSee('RM 250.00 refunded');
    }

    public function test_damaged_pairs_can_stay_off_sale(): void
    {
        $this->hitPayRefunds();

        $this->refund(['restock' => []])->assertSessionHas('success');

        $this->assertSame(0, $this->dunk->fresh()->stock);
        $this->assertSame(ProductStatus::Sold, $this->dunk->fresh()->status);
        $this->assertFalse($this->return->items->first()->fresh()->restocked);
    }

    public function test_refund_cannot_exceed_what_was_paid(): void
    {
        Http::fake();

        $this->refund(['amount' => '310.01'])
            ->assertSessionHas('error', "The refund can't be more than RM 310.00, the amount paid and not yet refunded.");

        Http::assertNothingSent();
        $this->assertSame(ReturnStatus::Approved, $this->return->fresh()->status);
        $this->assertSame(0, $this->dunk->fresh()->stock);
    }

    public function test_only_approved_returns_can_be_refunded(): void
    {
        Http::fake();
        $order = $this->returnableOrder();
        $requested = ReturnRequest::factory()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)->post(route('admin.returns.refund', $requested), ['amount' => '10', 'method' => 'manual', 'reference' => 'x'])
            ->assertSessionHas('error', 'Only an approved return can be refunded. This one is Return requested.');

        Http::assertNothingSent();
        $this->assertSame(ReturnStatus::Requested, $requested->fresh()->status);
    }

    public function test_a_return_cannot_be_refunded_twice(): void
    {
        $this->hitPayRefunds();

        $this->refund()->assertSessionHas('success');
        $this->refund()->assertSessionHas('error');

        Http::assertSentCount(1);
        $this->assertSame('300.00', $this->order->fresh()->refunded_amount);
        $this->assertSame(1, $this->dunk->fresh()->stock);
    }

    public function test_hitpay_rejection_records_nothing_and_allows_a_retry(): void
    {
        Http::fake([self::REFUND_URL => Http::response(['message' => 'The payment cannot be refunded.'], 422)]);

        $this->refund()->assertSessionHas('error', "HitPay didn't refund this payment (The payment cannot be refunded.). You can try again or refund manually.");

        $return = $this->return->fresh();
        $this->assertSame(ReturnStatus::Approved, $return->status);
        $this->assertNull($return->refund_attempted_at, 'A definite rejection can be retried without a warning.');
        $this->assertSame('0.00', $this->order->fresh()->refunded_amount);
        $this->assertSame(0, $this->dunk->fresh()->stock);
    }

    public function test_no_answer_from_hitpay_blocks_a_blind_retry(): void
    {
        // First call: no answer (timeout). Second call: success.
        Http::fake([self::REFUND_URL => Http::sequence()
            ->pushFailedConnection()
            ->push(['id' => 'ref-after-retry', 'status' => 'succeeded', 'amount_refunded' => 300])]);

        $this->refund()->assertSessionHas('error', "We couldn't reach HitPay, so we can't tell whether the refund went through. Check the HitPay dashboard before trying again.");
        $this->assertNotNull($this->return->fresh()->refund_attempted_at);

        // Retrying without confirming is refused before any request is sent.
        $this->refund()->assertSessionHas('error');
        Http::assertSentCount(1);

        $this->actingAs($this->admin)->get(route('admin.returns.show', $this->return))
            ->assertSee(['result is unknown', 'I checked HitPay']);

        // After checking the dashboard, the admin confirms and retries.
        $this->refund(['retry_confirmed' => '1'])->assertSessionHas('success');
        Http::assertSentCount(2);
        $this->assertSame(ReturnStatus::Refunded, $this->return->fresh()->status);
        $this->assertSame('ref-after-retry', $this->return->fresh()->refund_reference);
    }

    public function test_hitpay_server_error_is_treated_as_unknown(): void
    {
        Http::fake([self::REFUND_URL => Http::response(['message' => 'Server error'], 500)]);

        $this->refund()->assertSessionHas('error', "HitPay returned an error (500), so we can't tell whether the refund went through. Check the HitPay dashboard before trying again.");

        $this->assertNotNull($this->return->fresh()->refund_attempted_at);
        $this->assertSame(ReturnStatus::Approved, $this->return->fresh()->status);
    }

    public function test_manual_refund_for_cash_on_delivery(): void
    {
        Http::fake();
        $cod = $this->returnableOrder(['payment_method' => 'cod', 'gateway_payment_id' => null, 'gateway_reference' => null]);
        $this->return = $this->approvedReturn($cod);

        $this->actingAs($this->admin)->get(route('admin.returns.show', $this->return))
            ->assertSee('Not available: this order has no real HitPay payment');

        $this->refund(['method' => 'hitpay'])
            ->assertSessionHas('error', 'This order has no HitPay payment to refund. Refund it manually and record the reference.');
        $this->refund(['method' => 'manual', 'reference' => ''])
            ->assertSessionHas('error', 'Enter a reference for the manual refund (e.g. bank transfer or cash receipt number).');

        $this->refund(['method' => 'manual', 'reference' => 'Maybank transfer 88812'])->assertSessionHas('success');

        Http::assertNothingSent();
        $return = $this->return->fresh();
        $this->assertSame(RefundMethod::Manual, $return->refund_method);
        $this->assertSame('Maybank transfer 88812', $return->refund_reference);
        $this->assertSame('300.00', $cod->fresh()->refunded_amount);
    }

    public function test_simulated_payments_must_be_refunded_manually(): void
    {
        Http::fake();
        $simulated = $this->returnableOrder(['gateway_payment_id' => 'SIMULATED-20261026120000']);
        $this->return = $this->approvedReturn($simulated);

        $this->refund(['method' => 'hitpay'])->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_admins_cannot_set_refunded_by_hand(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $this->order), ['status' => 'refunded'])
            ->assertSessionHas('error');

        $this->assertSame(OrderStatus::Completed, $this->order->fresh()->status);
    }
}
