<?php

namespace Tests\Feature\Returns;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customer side of returns, plus the admin's approve / reject decision.
 */
class ReturnRequestTest extends TestCase
{
    use BuildsReturnableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpReturnableOrder();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function requestReturn(?Order $order = null, array $overrides = [])
    {
        $order ??= $this->order;

        return $this->actingAs($order->user)->post(route('account.returns.store', $order->order_number), [
            'items' => [$order->items->first()->id],
            'reason' => 'not_as_described',
            'details' => 'The left shoe has a tear that was not in the photos.',
            ...$overrides,
        ]);
    }

    // --- Eligibility ------------------------------------------------------

    public function test_delivered_paid_orders_show_the_return_button_with_the_deadline(): void
    {
        // Delivered 2 days ago (UTC 03:00) → 7-day deadline shown in Malaysia time.
        $this->actingAs($this->customer)->get(route('account.orders.show', $this->order->order_number))
            ->assertSee('Request a return')
            ->assertSee($this->order->returnDeadline()->shopDateTime());

        $this->actingAs($this->customer)->get(route('account.returns.create', $this->order->order_number))
            ->assertOk()
            ->assertSee(['Nike Dunk Low', 'UK 8.5', 'Not as described (condition or details)']);
    }

    public function test_cod_orders_can_be_returned_once_the_cash_is_collected(): void
    {
        $cod = $this->returnableOrder(['payment_method' => 'cod', 'gateway_payment_id' => null]);

        $this->assertTrue($cod->canRequestReturn());
        $this->requestReturn($cod)->assertRedirect(route('account.orders.show', $cod->order_number));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function ineligibleOrders(): array
    {
        $notYet = 'Returns are available once your order has been delivered and paid.';

        return [
            'pending' => [['status' => OrderStatus::Pending, 'paid_at' => null, 'delivered_at' => null], $notYet],
            'paid, not shipped' => [['status' => OrderStatus::Paid, 'delivered_at' => null], $notYet],
            'shipped' => [['status' => OrderStatus::Shipped, 'delivered_at' => null], $notYet],
            'COD delivered, cash not collected' => [['status' => OrderStatus::Delivered, 'payment_method' => 'cod', 'paid_at' => null], $notYet],
            'cancelled' => [['status' => OrderStatus::Cancelled], $notYet],
            'refunded' => [['status' => OrderStatus::Refunded], $notYet],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('ineligibleOrders')]
    public function test_ineligible_orders_cannot_be_returned(array $attributes, string $reason): void
    {
        $order = $this->returnableOrder($attributes);

        $this->actingAs($order->user)->get(route('account.orders.show', $order->order_number))->assertDontSee('Request a return');
        $this->actingAs($order->user)->get(route('account.returns.create', $order->order_number))
            ->assertRedirect(route('account.orders.show', $order->order_number))
            ->assertSessionHas('error', $reason);
        $this->requestReturn($order)->assertSessionHas('error', $reason);

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_the_return_window_closes_after_seven_days(): void
    {
        // Delivered 2 days ago: 5 days left. Still open just before the deadline...
        $this->travel(5)->days();
        $this->travel(-1)->minutes();
        $this->assertTrue($this->order->fresh()->canRequestReturn());

        // ...closed just after it.
        $this->travel(2)->minutes();
        $this->requestReturn()->assertSessionHas('error', 'The 7-day return window for this order has closed.');
        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_another_customers_order_is_404(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('account.returns.create', $this->order->order_number))->assertNotFound();
        $this->actingAs($stranger)->post(route('account.returns.store', $this->order->order_number), [])->assertNotFound();
    }

    public function test_delivery_date_is_set_when_the_order_is_delivered(): void
    {
        $online = $this->returnableOrder(['status' => OrderStatus::Shipped, 'delivered_at' => null]);
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $online), ['status' => 'completed']);
        $this->assertNotNull($online->fresh()->delivered_at);
        $this->assertTrue($online->fresh()->canRequestReturn());

        $cod = $this->returnableOrder(['status' => OrderStatus::Shipped, 'payment_method' => 'cod', 'paid_at' => null, 'delivered_at' => null]);
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $cod), ['status' => 'delivered']);
        $deliveredAt = $cod->fresh()->delivered_at;
        $this->assertNotNull($deliveredAt);
        $this->assertFalse($cod->fresh()->canRequestReturn(), 'Not until the cash is collected.');

        $this->travel(1)->days();
        $this->actingAs($this->admin)->patch(route('admin.orders.status', $cod), ['status' => 'completed']);
        $this->assertEquals($deliveredAt, $cod->fresh()->delivered_at, 'The window starts at delivery, not at cash collection.');
        $this->assertTrue($cod->fresh()->canRequestReturn());
    }

    // --- Requesting -------------------------------------------------------

    public function test_customer_requests_a_return_with_photos(): void
    {
        $this->requestReturn(overrides: ['photos' => [
            UploadedFile::fake()->create('left.jpg', 200, 'image/jpeg'),
            UploadedFile::fake()->create('right.png', 200, 'image/png'),
        ]])
            ->assertRedirect(route('account.orders.show', $this->order->order_number))
            ->assertSessionHas('success');

        $return = ReturnRequest::sole();
        $this->assertSame(ReturnStatus::Requested, $return->status);
        $this->assertSame($this->customer->id, $return->user_id);
        $this->assertSame(1, $return->items()->count());
        $this->assertSame('300.00', $return->itemsTotal());
        $this->assertCount(2, $return->photos);
        foreach ($return->photos as $photo) {
            Storage::disk('local')->assertExists($photo->path);
            $this->assertStringStartsWith("returns/{$return->id}/", $photo->path);
        }

        $this->actingAs($this->customer)->get(route('account.orders.show', $this->order->order_number))
            ->assertSee('Return requested')
            ->assertDontSee('Request a return');
    }

    public function test_only_one_request_per_order(): void
    {
        $this->requestReturn()->assertSessionHas('success');
        $this->requestReturn()->assertSessionHas('error', 'A return has already been requested for this order.');

        $this->assertSame(1, ReturnRequest::count());
    }

    public function test_request_is_validated(): void
    {
        $otherOrdersItem = $this->returnableOrder()->items->first()->id;

        $this->requestReturn(overrides: [
            'items' => [$otherOrdersItem],
            'reason' => 'too-expensive',
            'details' => 'bad',
            'photos' => [
                UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('b.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('c.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('d.jpg', 100, 'image/jpeg'),
            ],
        ])->assertSessionHasErrors(['items.0', 'reason', 'details', 'photos']);

        $this->requestReturn(overrides: ['items' => [], 'photos' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')]])
            ->assertSessionHasErrors(['items', 'photos.0']);

        $this->assertSame(0, ReturnRequest::count());
    }

    // --- Photos are private -----------------------------------------------

    public function test_photos_are_only_visible_to_the_owner_and_admins(): void
    {
        $this->requestReturn(overrides: ['photos' => [UploadedFile::fake()->create('left.jpg', 50, 'image/jpeg')]]);
        $photo = ReturnRequest::sole()->photos->first();

        $this->actingAs($this->customer)->get(route('returns.photo', $photo))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('returns.photo', $photo))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('returns.photo', $photo))->assertNotFound();

        auth()->logout();
        $this->get(route('returns.photo', $photo))->assertRedirect(route('login'));
    }

    // --- Admin decision ---------------------------------------------------

    public function test_admin_approves_and_the_customer_sees_the_return_address(): void
    {
        $this->requestReturn();
        $return = ReturnRequest::sole();

        $this->actingAs($this->admin)->get(route('admin.returns.index'))
            ->assertSee([$this->order->order_number, 'Return requested', '1 need attention']);
        $this->actingAs($this->admin)->get(route('admin.returns.show', $return))
            ->assertSee(['The left shoe has a tear', 'Approve', 'Reject'])
            ->assertDontSee('Record refund');

        $this->actingAs($this->admin)->patch(route('admin.returns.decide', $return), ['decision' => 'approve'])
            ->assertSessionHas('success');
        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);

        $this->actingAs($this->customer)->get(route('account.orders.show', $this->order->order_number))
            ->assertSee(['Return approved', 'Jalan Kasut 3', $this->order->order_number]);
        $this->actingAs($this->admin)->get(route('admin.returns.show', $return))->assertSee('Record refund');
    }

    public function test_rejecting_needs_a_note_that_the_customer_sees(): void
    {
        $this->requestReturn();
        $return = ReturnRequest::sole();

        $this->actingAs($this->admin)->patch(route('admin.returns.decide', $return), ['decision' => 'reject'])
            ->assertSessionHasErrors('admin_note');
        $this->assertSame(ReturnStatus::Requested, $return->fresh()->status);

        $this->actingAs($this->admin)->patch(route('admin.returns.decide', $return), ['decision' => 'reject', 'admin_note' => 'The tear is visible in listing photo 3.']);
        $this->assertSame(ReturnStatus::Rejected, $return->fresh()->status);

        $this->actingAs($this->customer)->get(route('account.orders.show', $this->order->order_number))
            ->assertSee(['Return rejected', 'The tear is visible in listing photo 3.']);

        // A decision is final.
        $this->actingAs($this->admin)->patch(route('admin.returns.decide', $return), ['decision' => 'approve'])
            ->assertSessionHas('error');
        $this->assertSame(ReturnStatus::Rejected, $return->fresh()->status);
    }

    public function test_customers_cannot_use_admin_return_pages(): void
    {
        $this->requestReturn();
        $return = ReturnRequest::sole();

        $this->actingAs($this->customer)->get(route('admin.returns.index'))->assertForbidden();
        $this->actingAs($this->customer)->patch(route('admin.returns.decide', $return), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->customer)->post(route('admin.returns.refund', $return), [])->assertForbidden();
    }
}
