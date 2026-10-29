<?php

namespace Tests\Feature\Cart;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\CartRelease;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ShoppingCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cart for logged-in customers (login is required to add; each line is a timed hold, see
 * ReservationTest for the holds themselves).
 */
class CartTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $dunk;

    private Product $samba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'slug' => 'nike-dunk-low', 'size' => 8.5, 'price' => 300, 'stock' => 1]);
        $this->samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'slug' => 'adidas-samba-og', 'size' => 9, 'price' => 150, 'stock' => 3]);
        $this->actingAs($this->customer);
    }

    private function add(Product $product, ?int $quantity = null)
    {
        return $this->from(route('shop.show', $product->slug))
            ->post(route('cart.items.store'), array_filter(['product_id' => $product->id, 'quantity' => $quantity]));
    }

    private function headerCount(int $pairs): string
    {
        return 'aria-label="Cart, '.$pairs.' '.str('pair')->plural($pairs).'"';
    }

    // --- Login required ---------------------------------------------------

    public function test_guests_are_sent_to_login_and_back_to_the_product(): void
    {
        auth()->logout();

        $this->get(route('cart.index'))->assertRedirect(route('login'));
        $this->from(route('shop.show', 'nike-dunk-low'))->post(route('cart.items.store'), ['product_id' => $this->dunk->id])
            ->assertRedirect(route('login'));
        $this->assertSame(0, CartItem::count());

        // After logging in they land back on the product page (never on the POST-only URL).
        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])
            ->assertRedirect(route('shop.show', 'nike-dunk-low'));
    }

    public function test_guests_see_log_in_to_reserve_on_the_product_page(): void
    {
        auth()->logout();

        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('Log in to reserve this pair')
            ->assertSee(route('login', ['return' => '/shop/nike-dunk-low']), false)
            ->assertDontSee('action="'.route('cart.items.store').'"', false);
    }

    // --- Adding -----------------------------------------------------------

    public function test_customer_can_add_view_and_remove_items(): void
    {
        $this->add($this->dunk)
            ->assertRedirect(route('shop.show', 'nike-dunk-low'))
            ->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Added Nike Dunk Low (UK 8.5) to your cart. It\'s reserved for you until'));

        $this->add($this->samba, 2);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee(['Nike Dunk Low', 'Adidas Samba OG', 'RM 600.00', 'Subtotal (3 pairs)'])
            ->assertSee($this->headerCount(3), false);

        $this->delete(route('cart.items.destroy', $this->dunk->id))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('success', 'Removed Nike Dunk Low from your cart.');

        $this->assertSame(2, app(ShoppingCart::class)->count());
        $this->assertSame(0, CartRelease::count(), 'Removing it yourself is not a "release".');
    }

    public function test_empty_cart_page(): void
    {
        $this->get(route('cart.index'))
            ->assertSee('Your cart is empty.')
            ->assertSee($this->headerCount(0), false);
    }

    public function test_one_of_a_kind_items_cannot_be_added_twice(): void
    {
        $this->add($this->dunk);
        $this->add($this->dunk)->assertSessionHas('error', "Only 1 pair available, and it's already in your cart.");

        $this->assertSame(1, app(ShoppingCart::class)->count());
    }

    public function test_quantity_is_capped_at_stock(): void
    {
        $this->add($this->samba, 2);
        $this->add($this->samba, 2)->assertSessionHas('error');

        $this->assertSame(2, app(ShoppingCart::class)->quantityOf($this->samba));
    }

    public function test_sold_hidden_and_missing_products_cannot_be_added(): void
    {
        $sold = Product::factory()->sold()->create();
        $hidden = Product::factory()->hidden()->create();

        $this->post(route('cart.items.store'), ['product_id' => $sold->id])
            ->assertSessionHas('error', 'Sorry, someone else just bought this pair.');

        foreach ([$hidden->id, 999999] as $productId) {
            $this->post(route('cart.items.store'), ['product_id' => $productId])
                ->assertSessionHas('error', 'Sorry, this pair is no longer for sale.');
        }

        $this->assertSame(0, app(ShoppingCart::class)->count());
    }

    public function test_add_validates_input(): void
    {
        $this->post(route('cart.items.store'), ['product_id' => 'abc', 'quantity' => 0])
            ->assertSessionHasErrors(['product_id', 'quantity']);
    }

    // --- Updating ---------------------------------------------------------

    public function test_quantity_can_be_changed_within_stock(): void
    {
        $this->add($this->samba);

        $this->patch(route('cart.items.update', $this->samba->id), ['quantity' => 2])
            ->assertSessionHas('success', 'Your cart was updated.');
        $this->assertSame(2, app(ShoppingCart::class)->quantityOf($this->samba));

        $this->samba->update(['stock' => 2]);
        $this->patch(route('cart.items.update', $this->samba->id), ['quantity' => 3])
            ->assertSessionHas('success', 'Only 2 pairs available, so the quantity was set to 2.');
    }

    public function test_invalid_quantities_are_rejected(): void
    {
        $this->add($this->samba);

        $this->patch(route('cart.items.update', $this->samba->id), ['quantity' => 0])->assertSessionHasErrors('quantity');
        $this->patch(route('cart.items.update', $this->samba->id), ['quantity' => 'x'])->assertSessionHasErrors('quantity');
    }

    public function test_cart_page_shows_a_quantity_picker_only_for_multi_stock_items(): void
    {
        $this->add($this->dunk);
        $this->add($this->samba);

        $this->get(route('cart.index'))
            ->assertSee('id="qty-'.$this->samba->id.'"', false)
            ->assertDontSee('id="qty-'.$this->dunk->id.'"', false)
            ->assertSee('One of a kind');
    }

    // --- Items becoming unavailable ---------------------------------------

    public function test_sold_items_are_removed_with_a_reason_and_similar_pairs(): void
    {
        Product::factory()->create(['name' => 'Similar UK 8.5 Pair', 'size' => 8.5, 'stock' => 1]);
        $this->add($this->dunk);
        $this->add($this->samba);

        $this->dunk->update(['status' => 'sold', 'stock' => 0]);

        // Any shop page, not just the cart.
        $this->get(route('home'))
            ->assertSee('This pair was bought by another customer: Nike Dunk Low (UK 8.5). We removed it from your cart.')
            ->assertSee(['See other UK 8.5 shoes', 'Similar pairs available now:', 'Similar UK 8.5 Pair'])
            ->assertSee($this->headerCount(1), false);

        $this->get(route('cart.index'))
            ->assertDontSee('Nike Dunk Low')
            ->assertDontSee('bought by another customer'); // told once
    }

    public function test_hidden_and_deleted_items_are_removed(): void
    {
        $this->add($this->dunk);
        $this->add($this->samba);

        $this->dunk->update(['status' => 'hidden']);
        $this->samba->images()->delete();
        $this->samba->delete();

        $this->get(route('cart.index'))
            ->assertSee('Nike Dunk Low (UK 8.5) is no longer for sale and was removed from your cart.')
            ->assertSee('Adidas Samba OG (UK 9) is no longer for sale and was removed from your cart.')
            ->assertSee('Your cart is empty.');
    }

    public function test_an_item_in_the_customers_own_order_is_not_blamed_on_someone_else(): void
    {
        $this->add($this->dunk);

        // The customer bought it themselves (e.g. in another tab) and hasn't paid yet.
        $order = Order::factory()->for($this->customer)->online()->status(OrderStatus::Pending)->create(['order_number' => 'TS-20261026-MINE22']);
        OrderItem::factory()->for($order)->for($this->dunk)->create(['price' => 300]);
        $this->dunk->update(['status' => 'sold', 'stock' => 0]);

        $this->get(route('home'))
            ->assertSee('Nike Dunk Low (UK 8.5) is already in your order TS-20261026-MINE22, waiting for payment, so we removed it from your cart.')
            ->assertSee(route('account.orders.show', 'TS-20261026-MINE22'), false)
            ->assertDontSee('bought by another customer');
    }

    public function test_quantity_is_reduced_when_stock_drops(): void
    {
        $this->add($this->samba, 3);
        $this->samba->update(['stock' => 1]);

        $this->get(route('cart.index'))
            ->assertSee('Only 1 pair of Adidas Samba OG (UK 9) is left, so we updated your cart.')
            ->assertSee('Subtotal (1 pair)');
    }

    public function test_updating_an_item_that_sold_meanwhile_removes_it(): void
    {
        $this->add($this->samba);
        $this->samba->update(['status' => 'sold', 'stock' => 0]);

        $this->patch(route('cart.items.update', $this->samba->id), ['quantity' => 1])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error', 'Sorry, someone else just bought this pair.');

        $this->assertSame(0, app(ShoppingCart::class)->count());
    }

    // --- Saved carts --------------------------------------------------------

    public function test_cart_is_saved_in_the_database_and_survives_logout(): void
    {
        $this->add($this->samba, 2);

        $this->post(route('logout'));
        $this->actingAs($this->customer);

        $this->assertSame(2, app(ShoppingCart::class)->quantityOf($this->samba));
        $this->assertDatabaseHas('cart_items', ['product_id' => $this->samba->id, 'quantity' => 2]);
    }

    public function test_customers_never_see_each_others_carts(): void
    {
        $this->add($this->samba);

        $this->actingAs(User::factory()->create());
        $this->get(route('cart.index'))->assertSee('Your cart is empty.');
    }

    public function test_deleting_a_product_in_admin_tells_customers_who_had_it(): void
    {
        $this->add($this->samba);

        $this->samba->images()->delete();
        $this->samba->delete();

        $this->assertSame(0, CartItem::count());
        $this->assertSame(CartRelease::NO_LONGER_FOR_SALE, CartRelease::sole()->reason);
        $this->get(route('cart.index'))->assertSee('Adidas Samba OG (UK 9) is no longer for sale');
    }

    public function test_cart_page_links_to_checkout_with_shipping_and_total(): void
    {
        $this->add($this->dunk);

        $this->get(route('cart.index'))
            ->assertSee(route('checkout.create'), false)
            ->assertSee(['RM 300.00', 'RM 10.00', 'RM 310.00', 'Proceed to checkout']);
    }
}
