<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Timestamps are stored in UTC and displayed in Malaysia time (UTC+8).
 */
class DisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
    }

    /**
     * Placed 25 Oct 17:30 UTC (= 26 Oct 1:30 am in KL), paid 10 minutes later.
     */
    private function paidOrder(): Order
    {
        $order = Order::factory()->for($this->customer)->online()->status(OrderStatus::Paid)->create([
            'order_number' => 'TS-20261026-KLTIME',
            'total' => 310,
            'shipping_fee' => 10,
            'created_at' => Carbon::parse('2026-10-25 17:30:00', 'UTC'),
            'paid_at' => Carbon::parse('2026-10-25 17:40:00', 'UTC'),
        ]);
        OrderItem::factory()->for($order)->for(Product::factory()->create())->create(['price' => 300]);

        return $order;
    }

    public function test_storage_stays_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Kuala_Lumpur', config('app.display_timezone'));

        $order = $this->paidOrder();

        $this->assertSame('2026-10-25 17:30:00', DB::table('orders')->where('id', $order->id)->value('created_at'));
    }

    public function test_customer_pages_and_receipt_show_malaysia_time(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->customer);

        $this->get(route('account.orders.index'))->assertSee('26 Oct 2026')->assertDontSee('25 Oct 2026');

        $this->get(route('account.orders.show', $order->order_number))
            ->assertSee('Placed 26 Oct 2026, 1:30 am')
            ->assertSee('Payment received on 26 Oct 2026, 1:40 am')
            ->assertDontSee('5:30 pm');

        $this->get(route('account.orders.receipt', $order->order_number))
            ->assertSee(['26 Oct 2026, 1:30 am', '26 Oct 2026, 1:40 am'])
            ->assertDontSee('5:40 pm');
    }

    public function test_admin_pages_show_malaysia_time(): void
    {
        $order = $this->paidOrder();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.orders.index'))->assertSee('26 Oct 2026, 1:30 am');
        $this->actingAs($admin)->get(route('admin.orders.show', $order))
            ->assertSee(['Placed 26 Oct 2026, 1:30 am', '26 Oct 2026, 1:40 am'])
            ->assertDontSee('5:30 pm');
    }

    public function test_pay_by_time_is_shown_in_malaysia_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 02:00:00', 'UTC'));
        $order = Order::factory()->for($this->customer)->online()->create(['gateway_reference' => null]);

        $this->assertSame('2026-10-26 02:30:00', DB::table('orders')->where('id', $order->id)->value('payment_expires_at'));

        $this->actingAs($this->customer)->get(route('account.orders.show', $order->order_number))
            ->assertSee('Pay by <strong>10:30 am</strong>', false);
    }

    public function test_order_numbers_use_the_malaysian_date(): void
    {
        // 23:30 UTC on the 25th is already 7:30 am on the 26th in Kuala Lumpur.
        $this->travelTo(Carbon::parse('2026-10-25 23:30:00', 'UTC'));

        $this->assertStringStartsWith('TS-20261026-', Order::generateOrderNumber());
    }

    public function test_the_30_minute_payment_window_is_unaffected(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 23:50:00', 'UTC'));
        $order = Order::factory()->for($this->customer)->online()->create(['gateway_reference' => null]);
        $this->assertSame(PaymentMethod::Online, $order->payment_method);

        // 29 minutes later: still inside the window, crossing midnight in UTC.
        $this->travel(29)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        // Just past 30 minutes: the order fails.
        $this->travel(2)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
    }

    public function test_date_macros_convert_a_copy_only(): void
    {
        $utc = Carbon::parse('2026-10-25 16:05:00', 'UTC');

        $this->assertSame('26 Oct 2026', $utc->shopDate());
        $this->assertSame('26 Oct 2026, 12:05 am', $utc->shopDateTime());
        $this->assertSame('12:05 am', $utc->shopTime());
        $this->assertSame('UTC', $utc->getTimezone()->getName(), 'The original value is untouched.');
    }
}
