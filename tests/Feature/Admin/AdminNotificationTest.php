<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Notifications\AdminAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->otherAdmin = User::factory()->admin()->create();
        $this->customer = User::factory()->create(['name' => 'Aisyah Rahman']);
    }

    /**
     * @return list<string> the kinds of this user's notifications, oldest first
     */
    private function kinds(User $user): array
    {
        return $user->notifications()->oldest()->get()->pluck('data.kind')->all();
    }

    private function checkout(Product $product, int $quantity = 1)
    {
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => $quantity]);

        return $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => number_format((float) $product->price * $quantity + 10, 2, '.', ''),
            'payment_method' => 'cod',
        ]);
    }

    // --- Events -----------------------------------------------------------

    public function test_new_order_notifies_every_admin_and_no_customer(): void
    {
        $this->checkout(Product::factory()->create(['price' => 300, 'stock' => 1]));
        $order = Order::sole();

        foreach ([$this->admin, $this->otherAdmin] as $admin) {
            $this->assertSame(['order'], $this->kinds($admin));
            $data = $admin->notifications()->first()->data;
            $this->assertSame("New order {$order->order_number}", $data['title']);
            $this->assertSame('RM 310.00 · Cash on Delivery', $data['body']);
            $this->assertSame(route('admin.orders.show', $order), $data['url']);
        }
        $this->assertSame([], $this->kinds($this->customer));
    }

    public function test_a_failed_checkout_notifies_nobody(): void
    {
        $product = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $product->id]);
        $product->update(['stock' => 0, 'status' => ProductStatus::Sold]);

        $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah', 'phone' => '012-345 6789', 'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => '310.00', 'payment_method' => 'cod',
        ])->assertRedirect(route('cart.index'));

        $this->assertSame(0, Order::count());
        $this->assertSame([], $this->kinds($this->admin));
    }

    public function test_low_stock_only_when_a_multi_pair_product_drops_to_the_threshold(): void
    {
        $samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'size' => 9, 'price' => 100, 'stock' => 3]);
        $this->checkout($samba);

        $this->assertSame(['low_stock', 'order'], $this->kinds($this->admin));
        $lowStock = $this->admin->notifications()->get()->firstWhere('data.kind', 'low_stock')->data;
        $this->assertSame('Low stock: Adidas Samba OG (UK 9)', $lowStock['title']);
        $this->assertSame('2 pairs left', $lowStock['body']);

        // Already below the threshold: no repeat. A single pair selling out: nothing either.
        $this->admin->notifications()->delete();
        $this->checkout($samba->fresh());
        $this->checkout(Product::factory()->create(['price' => 100, 'stock' => 1]));
        $this->assertSame(['order', 'order'], $this->kinds($this->admin));
    }

    public function test_failed_payment_notifies_once(): void
    {
        $order = Order::factory()->online()->create();
        OrderItem::factory()->for($order)->for(Product::factory()->sold()->create())->create();

        $this->assertTrue($order->markPaymentFailed());
        $this->assertFalse($order->markPaymentFailed());

        $this->assertSame(['payment_failed'], $this->kinds($this->admin));
        $this->assertSame("Payment failed for {$order->order_number}", $this->admin->notifications()->first()->data['title']);
    }

    public function test_expiry_command_notifies_about_expired_payments(): void
    {
        $order = Order::factory()->online()->create(['payment_expires_at' => now()->subMinute(), 'gateway_reference' => null]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertSame(['payment_failed'], $this->kinds($this->admin));
    }

    public function test_return_request_notifies_admins(): void
    {
        Storage::fake('local');
        $order = Order::factory()->for($this->customer)->online()->create([
            'status' => OrderStatus::Completed, 'paid_at' => now()->subDays(3), 'delivered_at' => now()->subDay(), 'total' => 310,
        ]);
        $item = OrderItem::factory()->for($order)->for(Product::factory()->sold()->create())->create();

        $this->actingAs($this->customer)->post(route('account.returns.store', $order->order_number), [
            'items' => [$item->id], 'reason' => 'doesnt_fit', 'details' => 'Runs half a size small for me.',
        ])->assertSessionHas('success');

        $data = $this->admin->notifications()->sole()->data;
        $this->assertSame("Return requested for {$order->order_number}", $data['title']);
        $this->assertSame("Doesn't fit", $data['body']);
    }

    public function test_customer_message_notifies_admins_but_spam_does_not(): void
    {
        $payload = ['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'topic' => 'order', 'message' => 'Where is my parcel please?'];

        $this->post(route('pages.customer-service.store'), [...$payload, 'website' => 'spam']);
        $this->assertSame([], $this->kinds($this->admin));

        $this->post(route('pages.customer-service.store'), $payload);
        $data = $this->admin->notifications()->sole()->data;
        $this->assertSame('New message from Aisyah Rahman', $data['title']);
        $this->assertSame(route('admin.messages.show', ContactMessage::sole()), $data['url']);
    }

    // --- Bell UI ----------------------------------------------------------

    private function notify(User $admin, string $title = 'New order TS-20261028-AAAAAA', string $url = '/admin/orders'): string
    {
        $admin->notify(new AdminAlert('order', $title, url($url), 'RM 310.00 · Cash on Delivery'));

        return $admin->notifications()->latest()->first()->id;
    }

    public function test_bell_shows_unread_count_and_latest_items(): void
    {
        $this->notify($this->admin, 'New order TS-20261028-AAAAAA');
        $this->notify($this->admin, 'New order TS-20261028-BBBBBB');
        $this->notify($this->otherAdmin, 'Someone else’s order');

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertSee('Notifications, 2 unread')
            ->assertSee(['New order TS-20261028-AAAAAA', 'New order TS-20261028-BBBBBB', 'Mark all as read'])
            ->assertDontSee('Someone else’s order');
    }

    public function test_opening_marks_read_and_redirects_to_the_item(): void
    {
        $id = $this->notify($this->admin, url: '/admin/orders?status=pending');

        $this->actingAs($this->admin)->post(route('admin.notifications.open', $id))
            ->assertRedirect(url('/admin/orders?status=pending'));

        $this->assertNotNull($this->admin->notifications()->find($id)->read_at);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('Notifications, 0 unread');
    }

    public function test_mark_all_as_read_and_unread_filter(): void
    {
        $this->notify($this->admin);
        $this->notify($this->admin);
        $this->notify($this->otherAdmin);

        $this->actingAs($this->admin)->get(route('admin.notifications.index', ['filter' => 'unread']))->assertSee('New order TS-20261028-AAAAAA');

        $this->actingAs($this->admin)->post(route('admin.notifications.read-all'))->assertSessionHas('success');

        $this->assertSame(0, $this->admin->unreadNotifications()->count());
        $this->assertSame(1, $this->otherAdmin->unreadNotifications()->count(), "Another admin's notifications are untouched.");
        $this->actingAs($this->admin)->get(route('admin.notifications.index', ['filter' => 'unread']))->assertSee('No unread notifications.');
    }

    public function test_admins_cannot_open_each_others_notifications_and_customers_are_forbidden(): void
    {
        $id = $this->notify($this->otherAdmin);

        $this->actingAs($this->admin)->post(route('admin.notifications.open', $id))->assertNotFound();
        $this->assertNull($this->otherAdmin->notifications()->find($id)->read_at);

        $this->actingAs($this->customer)->get(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($this->customer)->post(route('admin.notifications.read-all'))->assertForbidden();
    }
}
