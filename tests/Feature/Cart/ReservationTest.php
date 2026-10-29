<?php

namespace Tests\Feature\Cart;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartRelease;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductWatch;
use App\Models\User;
use App\Notifications\ProductAvailableAgain;
use App\Services\Cart\Reservations;
use App\Services\ShoppingCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Timed cart holds (App\Services\Cart\Reservations): adding a pair holds it for
 * shop.cart.hold_minutes (15), never extended; others can't add or buy it meanwhile.
 */
class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $aisyah;

    private User $daniel;

    private Product $dunk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0)); // 10:00 UTC = 6:00 pm in Malaysia
        $this->aisyah = User::factory()->create(['name' => 'Aisyah']);
        $this->daniel = User::factory()->create(['name' => 'Daniel']);
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'slug' => 'nike-dunk-low', 'size' => 8.5, 'price' => 300, 'stock' => 1]);
    }

    private function add(User $user, Product $product, int $quantity = 1)
    {
        return $this->actingAs($user)
            ->from(route('shop.show', $product->slug))
            ->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function checkout(User $user, string $expectedTotal)
    {
        return $this->actingAs($user)->post(route('checkout.store'), [
            'recipient_name' => $user->name,
            'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => $expectedTotal,
            'payment_method' => 'cod',
        ]);
    }

    // --- Two customers, one pair --------------------------------------------

    public function test_a_held_pair_cannot_be_added_by_someone_else(): void
    {
        $this->add($this->aisyah, $this->dunk)
            ->assertSessionHas('success', "Added Nike Dunk Low (UK 8.5) to your cart. It's reserved for you until 6:15 pm.");

        $this->add($this->daniel, $this->dunk)
            ->assertSessionHas('error', 'Reserved by another shopper. It may be available again around 6:15 pm.');

        $this->assertSame(1, CartItem::count());
        $this->assertSame(0, app(ShoppingCart::class)->count(), "Nothing in Daniel's cart.");
    }

    public function test_the_product_page_explains_the_hold_to_each_side(): void
    {
        $this->add($this->aisyah, $this->dunk);

        $this->actingAs($this->aisyah)->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('data-held-by-me', false)
            ->assertSee('Reserved for you until <strong>6:15 pm</strong>', false);

        $this->actingAs($this->daniel)->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('data-reserved-by-other', false)
            ->assertSee(['Reserved by another shopper', '6:15 pm', 'in about 15 min', "Notify me when it's free"])
            ->assertDontSee('action="'.route('cart.items.store').'"', false);

        // Guests see the note too, plus the login link.
        auth()->logout();
        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee(['Reserved by another shopper', 'Log in to reserve this pair']);

        // Listings mark it, for everyone but the holder.
        $this->actingAs($this->daniel)->get(route('shop.index'))->assertSee('data-reserved', false);
        $this->actingAs($this->aisyah)->get(route('shop.index'))->assertDontSee('data-reserved', false);
    }

    public function test_only_free_pairs_can_be_held_when_there_are_several(): void
    {
        $samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'size' => 9, 'stock' => 3]);

        $this->add($this->aisyah, $samba, 2);
        $this->add($this->daniel, $samba, 2)->assertSessionHas('error', 'Only 1 pair available right now.');
        $this->add($this->daniel, $samba, 1)->assertSessionHas('success');

        $this->add(User::factory()->create(), $samba)
            ->assertSessionHas('error', fn (string $message) => str_starts_with($message, 'Reserved by another shopper.'));
    }

    public function test_someone_else_cannot_buy_a_held_pair_even_if_it_is_still_in_their_old_cart(): void
    {
        // Aisyah holds the pair; Daniel still has an old, expired line for it that nothing has
        // cleaned up yet (written directly: reserving always releases expired lines first).
        $this->add($this->aisyah, $this->dunk)->assertSessionHas('success');
        Cart::create(['user_id' => $this->daniel->id])->items()->create([
            'product_id' => $this->dunk->id, 'quantity' => 1, 'reserved_until' => now()->subMinute(),
        ]);

        $this->checkout($this->daniel, '310.00')->assertRedirect(route('cart.index'));
        $this->assertSame(0, Order::count());

        $this->checkout($this->aisyah, '310.00')->assertRedirect();
        $this->assertSame(1, Order::count());
        $this->assertSame($this->aisyah->id, Order::sole()->user_id);
    }

    // --- Expiry -------------------------------------------------------------

    public function test_after_the_hold_ends_someone_else_can_add_it_and_the_first_customer_is_told_why(): void
    {
        Product::factory()->create(['name' => 'Similar Jordan 1', 'size' => 8.5, 'stock' => 1]);
        $this->add($this->aisyah, $this->dunk);

        $this->travel(15)->minutes();
        $this->add($this->daniel, $this->dunk)->assertSessionHas('success');

        $release = CartRelease::sole();
        $this->assertSame([$this->aisyah->id, CartRelease::EXPIRED], [$release->user_id, $release->reason]);

        $this->actingAs($this->aisyah)->get(route('cart.index'))
            ->assertSee('Your reservation for Nike Dunk Low (UK 8.5) expired, so we removed it from your cart.')
            ->assertSee(['Similar pairs available now:', 'Similar Jordan 1'])
            ->assertSee('Your cart is empty.');

        // Told once only.
        $this->actingAs($this->aisyah)->get(route('cart.index'))->assertDontSee('reservation for Nike Dunk Low expired');
    }

    public function test_expired_holds_are_released_lazily_on_a_page_view(): void
    {
        $this->add($this->aisyah, $this->dunk);
        $this->travel(16)->minutes();

        // Nobody runs the scheduler; any visitor's page view cleans up.
        auth()->logout();
        $this->get(route('home'))->assertOk();

        $this->assertSame(0, CartItem::count());
        $this->assertSame(CartRelease::EXPIRED, CartRelease::sole()->reason);
    }

    public function test_the_scheduled_command_releases_expired_holds_exactly_once(): void
    {
        $this->add($this->aisyah, $this->dunk);
        $this->travel(16)->minutes();

        $this->artisan('cart:release-expired')->expectsOutputToContain('Released 1')->assertSuccessful();
        $this->artisan('cart:release-expired')->assertSuccessful();
        app(Reservations::class)->releaseExpired();

        $this->assertSame(1, CartRelease::count());
    }

    public function test_an_expired_line_never_counts_even_before_cleanup(): void
    {
        $this->add($this->aisyah, $this->dunk);
        $this->travel(15)->minutes(); // exactly at expiry, before any release

        $this->assertSame(0, Reservations::heldByOthers($this->dunk->id, $this->daniel->id));
        $this->actingAs($this->aisyah);
        $this->assertSame(0, app(ShoppingCart::class)->count());
    }

    public function test_holds_are_never_extended(): void
    {
        $samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'size' => 9, 'stock' => 3]);
        $this->add($this->aisyah, $samba);
        $until = CartItem::sole()->reserved_until;

        $this->travel(10)->minutes();
        $this->add($this->aisyah, $samba);
        $this->patch(route('cart.items.update', $samba->id), ['quantity' => 3]);
        $this->get(route('cart.index'));
        $this->get(route('checkout.create'));

        $this->assertTrue(CartItem::sole()->reserved_until->equalTo($until));
        $this->assertSame(3, CartItem::sole()->quantity);

        $this->travel(5)->minutes();
        $this->get(route('cart.index'))->assertSee('Your reservation for Adidas Samba OG (UK 9) expired');
    }

    public function test_changing_the_quantity_after_the_hold_ended_does_not_silently_hold_it_again(): void
    {
        $samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'size' => 9, 'stock' => 3]);
        $this->add($this->aisyah, $samba);
        $this->travel(16)->minutes();
        app(Reservations::class)->releaseExpired();

        $this->patch(route('cart.items.update', $samba->id), ['quantity' => 2])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error', 'Your reservation for this pair ended, so it is no longer in your cart.');

        $this->assertSame(0, CartItem::count());
    }

    public function test_cart_shows_a_countdown_for_each_hold(): void
    {
        $this->add($this->aisyah, $this->dunk);

        $this->get(route('cart.index'))
            ->assertSee('data-hold-countdown', false)
            ->assertSee('data-expires-at="'.now()->addMinutes(15)->toIso8601String().'"', false)
            ->assertSee('Reserved for you until 6:15 pm');
    }

    // --- Checkout and orders ------------------------------------------------

    public function test_checkout_after_the_hold_expired_places_nothing_and_says_why(): void
    {
        $this->add($this->aisyah, $this->dunk);
        $this->travel(16)->minutes();

        $this->checkout($this->aisyah, '310.00')
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error', "Your reservation ran out before the order was placed, so nothing was ordered or charged. Add the pair again if it's still free.");

        $this->assertSame(0, Order::count());
        $this->assertSame(1, $this->dunk->fresh()->stock);
        $this->get(route('cart.index'))->assertSee('Your reservation for Nike Dunk Low (UK 8.5) expired');
    }

    public function test_placing_the_order_ends_the_hold_and_the_pending_order_keeps_the_pair(): void
    {
        $this->add($this->aisyah, $this->dunk);
        $this->checkout($this->aisyah, '310.00')->assertRedirect();

        $this->assertSame(0, CartItem::count());
        $this->assertSame(0, CartRelease::count(), 'Buying it is not a release.');
        $this->assertSame([0, ProductStatus::Sold], [$this->dunk->fresh()->stock, $this->dunk->fresh()->status]);

        // Long after the hold time, the pending order still keeps it.
        $this->travel(1)->hours();
        $this->add($this->daniel, $this->dunk)->assertSessionHas('error', 'Sorry, someone else just bought this pair.');
    }

    public function test_a_cancelled_order_frees_the_pair_for_new_holds(): void
    {
        $this->add($this->aisyah, $this->dunk);
        $this->checkout($this->aisyah, '310.00');

        Order::sole()->transitionTo(OrderStatus::Cancelled);

        $this->add($this->daniel, $this->dunk)->assertSessionHas('success');
    }

    // --- Limits ---------------------------------------------------------------

    public function test_a_cart_holds_at_most_three_pairs(): void
    {
        $products = Product::factory()->count(4)->create(['stock' => 1]);

        foreach ($products->take(3) as $product) {
            $this->add($this->aisyah, $product)->assertSessionHas('success');
        }
        $this->add($this->aisyah, $products[3])
            ->assertSessionHas('error', 'Your cart can hold up to 3 pairs at a time. Check out or remove a pair first.');

        $this->assertSame(3, CartItem::count());
    }

    public function test_adding_is_rate_limited(): void
    {
        config(['shop.cart.adds_per_minute' => 2]);
        $products = Product::factory()->count(3)->create(['stock' => 1]);

        $this->add($this->aisyah, $products[0])->assertSessionHas('success');
        $this->add($this->aisyah, $products[1])->assertSessionHas('success');
        $this->add($this->aisyah, $products[2])
            ->assertSessionHas('error', "You're adding to your cart too quickly. Please wait a minute and try again.");

        $this->assertSame(2, CartItem::count());
    }

    // --- Admin ----------------------------------------------------------------

    public function test_admins_see_repeated_expired_holds_on_the_order_page_and_reservations_page(): void
    {
        foreach (Product::factory()->count(3)->create(['stock' => 1]) as $product) {
            $this->add($this->aisyah, $product);
        }
        $this->travel(16)->minutes();
        app(Reservations::class)->releaseExpired();
        $this->add($this->daniel, $this->dunk); // an active hold

        $order = Order::factory()->for($this->aisyah)->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.orders.show', $order))
            ->assertSee('data-expired-holds', false)
            ->assertSee(['Expired cart holds (30 days):', 'Repeated']);

        $this->actingAs($admin)->get(route('admin.reservations.index'))
            ->assertOk()
            ->assertSee(['Nike Dunk Low', 'Daniel', $this->aisyah->email]);

        // Older than the window: not counted.
        $this->travel(31)->days();
        $this->assertSame(0, $this->aisyah->expiredHoldCount());
    }

    public function test_customers_cannot_open_the_reservations_page(): void
    {
        $this->actingAs($this->aisyah)->get(route('admin.reservations.index'))->assertForbidden();
    }

    // --- Notify me -------------------------------------------------------------

    public function test_watchers_get_one_email_when_the_pair_is_free_again(): void
    {
        Notification::fake();
        $this->add($this->aisyah, $this->dunk);

        $this->actingAs($this->daniel)->post(route('shop.watch', 'nike-dunk-low'))
            ->assertSessionHas('success', "We'll email {$this->daniel->email} once Nike Dunk Low is free again.");
        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee("Don't notify me");

        $this->travel(16)->minutes();
        $this->artisan('cart:release-expired');

        Notification::assertSentToTimes($this->daniel, ProductAvailableAgain::class, 1);
        Notification::assertNotSentTo($this->aisyah, ProductAvailableAgain::class);
        $this->assertSame(0, ProductWatch::count());
    }

    public function test_watchers_are_not_emailed_when_the_pair_was_bought(): void
    {
        Notification::fake();
        $this->add($this->aisyah, $this->dunk);
        $this->actingAs($this->daniel)->post(route('shop.watch', 'nike-dunk-low'));

        $this->checkout($this->aisyah, '310.00');
        $this->travel(16)->minutes();
        $this->artisan('cart:release-expired');

        Notification::assertNothingSent();
        $this->assertSame(1, ProductWatch::count(), 'Still watching in case the order is cancelled.');

        Order::sole()->transitionTo(OrderStatus::Cancelled);
        Notification::assertSentToTimes($this->daniel, ProductAvailableAgain::class, 1);
    }

    public function test_watching_can_be_turned_off(): void
    {
        $this->add($this->aisyah, $this->dunk);
        $this->actingAs($this->daniel)->post(route('shop.watch', 'nike-dunk-low'));
        $this->actingAs($this->daniel)->post(route('shop.watch', 'nike-dunk-low'))
            ->assertSessionHas('success', "OK, we won't email you about Nike Dunk Low.");

        $this->assertSame(0, ProductWatch::count());
    }
}
