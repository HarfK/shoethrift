<?php

namespace Tests\Feature\Cart;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ShoppingCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private Product $dunk;

    private Product $samba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'slug' => 'nike-dunk-low', 'size' => 8.5, 'price' => 300, 'stock' => 1]);
        $this->samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'slug' => 'adidas-samba-og', 'size' => 9, 'price' => 150, 'stock' => 3]);
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

    // --- Adding -----------------------------------------------------------

    public function test_guest_can_add_view_and_remove_items(): void
    {
        $this->add($this->dunk)
            ->assertRedirect(route('shop.show', 'nike-dunk-low'))
            ->assertSessionHas('success', 'Added Nike Dunk Low (UK 8.5) to your cart.');

        $this->add($this->samba, 2);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee(['Nike Dunk Low', 'Adidas Samba OG', 'RM 300.00', 'RM 300.00', 'RM 600.00'])
            ->assertSee('Subtotal (3 pairs)')
            ->assertSee('Log in to check out')
            ->assertSee($this->headerCount(3), false);

        $this->delete(route('cart.items.destroy', $this->dunk->id))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('success', 'Removed Nike Dunk Low from your cart.');

        $this->get(route('cart.index'))
            ->assertSee('Removed Nike Dunk Low from your cart.')
            ->assertDontSee(route('shop.show', 'nike-dunk-low'), false)
            ->assertSee(route('shop.show', 'adidas-samba-og'), false)
            ->assertSee($this->headerCount(2), false);
    }

    public function test_empty_cart_page(): void
    {
        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('Your cart is empty.')
            ->assertSee('Shop now')
            ->assertSee($this->headerCount(0), false);
    }

    public function test_one_of_a_kind_items_cannot_be_added_twice(): void
    {
        $this->add($this->dunk);

        $this->add($this->dunk)
            ->assertSessionHas('error', "Only 1 pair available. It's already in your cart.");

        $this->assertSame(1, app(ShoppingCart::class)->count());
        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('In your cart')
            ->assertDontSee('Add to cart');
    }

    public function test_quantity_is_capped_at_stock(): void
    {
        $this->add($this->samba, 2);
        $this->add($this->samba, 5); // only 1 more available

        $this->assertSame(3, app(ShoppingCart::class)->quantityOf($this->samba));

        $this->add($this->samba)->assertSessionHas('error', 'All 3 available pairs are already in your cart.');
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
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('success', 'Your cart was updated.');
        $this->assertSame(2, app(ShoppingCart::class)->quantityOf($this->samba));

        $this->patch(route('cart.items.update', $this->samba->id), ['quantity' => 9])
            ->assertSessionHas('success', 'Only 3 pairs available, so the quantity was set to 3.');
        $this->assertSame(3, app(ShoppingCart::class)->quantityOf($this->samba));
    }

    public function test_invalid_quantities_are_rejected(): void
    {
        $this->add($this->samba);

        foreach ([0, -1, 'abc'] as $quantity) {
            $this->patch(route('cart.items.update', $this->samba->id), ['quantity' => $quantity])
                ->assertSessionHasErrors('quantity');
        }

        $this->assertSame(1, app(ShoppingCart::class)->quantityOf($this->samba));
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

    public function test_sold_items_are_removed_with_a_notice_on_the_next_page(): void
    {
        $this->add($this->dunk);
        $this->add($this->samba);

        $this->dunk->update(['status' => 'sold', 'stock' => 0]);

        // Any shop page, not just the cart.
        $this->get(route('home'))
            ->assertSee('Sorry, someone else just bought Nike Dunk Low (UK 8.5), so we removed it from your cart.')
            ->assertSee('See other UK 8.5 shoes')
            ->assertSee(route('shop.index', ['size' => '8.5']), false)
            ->assertSee($this->headerCount(1), false);

        $this->get(route('cart.index'))
            ->assertDontSee('Nike Dunk Low')
            ->assertDontSee('someone else just bought'); // told once
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
            ->assertSee('An item in your cart is no longer for sale and was removed.')
            ->assertDontSee('someone else just bought')
            ->assertSee('Your cart is empty.');
    }

    public function test_an_item_in_the_customers_own_order_is_not_blamed_on_someone_else(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->add($this->dunk);

        // The customer bought it themselves (e.g. from another device) and hasn't paid yet.
        $order = Order::factory()->for($customer)->online()->status(OrderStatus::Pending)->create(['order_number' => 'TS-20261026-MINE22']);
        OrderItem::factory()->for($order)->for($this->dunk)->create(['price' => 300]);
        $this->dunk->update(['status' => 'sold', 'stock' => 0]);

        $this->get(route('home'))
            ->assertSee('Nike Dunk Low (UK 8.5) is already in your order TS-20261026-MINE22, waiting for payment, so we removed it from your cart.')
            ->assertSee(route('account.orders.show', 'TS-20261026-MINE22'), false)
            ->assertDontSee('someone else just bought');
    }

    public function test_someone_elses_order_still_says_someone_else(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->add($this->dunk);

        $order = Order::factory()->status(OrderStatus::Pending)->create();
        OrderItem::factory()->for($order)->for($this->dunk)->create(['price' => 300]);
        $this->dunk->update(['status' => 'sold', 'stock' => 0]);

        $this->get(route('home'))
            ->assertSee('Sorry, someone else just bought Nike Dunk Low (UK 8.5), so we removed it from your cart.')
            ->assertDontSee($order->order_number);
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
            ->assertSessionHas('error', 'Sorry, someone else just bought Adidas Samba OG (UK 9), so we removed it from your cart.');

        $this->assertSame(0, app(ShoppingCart::class)->count());
    }

    // --- Logged-in carts --------------------------------------------------

    public function test_logged_in_cart_is_saved_in_the_database_and_survives_logout(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer);
        $this->add($this->samba, 2);

        $this->assertDatabaseHas('cart_items', ['product_id' => $this->samba->id, 'quantity' => 2]);
        $this->assertSame($customer->id, $customer->cart->user_id);

        $this->post('/logout');
        $this->assertGuest();
        $this->get(route('cart.index'))->assertSee('Your cart is empty.');

        $this->post('/login', ['email' => $customer->email, 'password' => 'password']);
        $this->get(route('cart.index'))->assertSee('Adidas Samba OG')->assertSee('Subtotal (2 pairs)');
    }

    public function test_guest_cart_is_merged_on_login_without_duplicates_or_exceeding_stock(): void
    {
        $customer = User::factory()->create();
        $saved = $customer->cart()->create();
        $saved->items()->create(['product_id' => $this->samba->id, 'quantity' => 1]);

        // As a guest: the same Samba (2 pairs) plus the Dunk.
        $this->add($this->samba, 2);
        $this->add($this->dunk);

        $this->post('/login', ['email' => $customer->email, 'password' => 'password']);

        $this->assertSame(2, CartItem::where('product_id', $this->samba->id)->value('quantity'), 'Keeps the larger quantity, not 1 + 2.');
        $this->assertSame(1, CartItem::where('product_id', $this->dunk->id)->value('quantity'));
        $this->assertSame(2, CartItem::count());
        $this->assertSame([], session(ShoppingCart::SESSION_KEY, []), 'Guest cart is cleared after merging.');

        $this->get(route('cart.index'))->assertSee($this->headerCount(3), false);
    }

    public function test_guest_cart_is_kept_on_registration(): void
    {
        $this->add($this->dunk);

        $this->post('/register', [
            'name' => 'New Shopper',
            'email' => 'new@example.com',
            'phone' => '013-222 3333',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame([$this->dunk->id], $user->cart->items()->pluck('product_id')->all());
    }

    public function test_items_that_sold_while_in_a_guest_cart_are_not_merged(): void
    {
        $customer = User::factory()->create();
        $this->add($this->dunk);
        $this->dunk->update(['status' => 'sold', 'stock' => 0]);

        $this->post('/login', ['email' => $customer->email, 'password' => 'password']);

        $this->assertSame(0, CartItem::count());
    }

    public function test_customers_never_see_each_others_carts(): void
    {
        $aisyah = User::factory()->create();
        $daniel = User::factory()->create();

        $this->actingAs($aisyah);
        $this->add($this->dunk);

        $this->actingAs($daniel);
        $this->get(route('cart.index'))->assertSee('Your cart is empty.');

        // Daniel can't remove Aisyah's item either: removal only touches his own cart.
        $this->delete(route('cart.items.destroy', $this->dunk->id));
        $this->assertSame(1, $aisyah->cart->items()->count());
    }

    public function test_deleting_a_product_in_admin_clears_it_from_saved_carts(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->add($this->samba);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.products.destroy', $this->samba))
            ->assertSessionHas('success');

        $this->assertSame(0, CartItem::count());
    }

    public function test_logged_in_cart_page_links_to_checkout_with_shipping_and_total(): void
    {
        $this->actingAs(User::factory()->create());
        $this->add($this->dunk);

        $this->get(route('cart.index'))
            ->assertSee('Proceed to checkout')
            ->assertSee(route('checkout.create'), false)
            ->assertSee(['RM 10.00', 'RM 310.00']) // flat shipping + total
            ->assertDontSee('Log in to check out');
    }
}
