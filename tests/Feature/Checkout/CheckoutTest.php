<?php

namespace Tests\Feature\Checkout;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ShoppingCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $dunk;

    private Product $samba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create([
            'name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'address' => "12 Jalan Bunga\n40100 Shah Alam, Selangor",
        ]);
        $this->dunk = Product::factory()->create(['name' => 'Nike Dunk Low', 'size' => 8.5, 'price' => 300, 'stock' => 1]);
        $this->samba = Product::factory()->create(['name' => 'Adidas Samba OG', 'size' => 9, 'price' => 149.90, 'stock' => 3]);
    }

    private function addToCart(Product $product, int $quantity = 1): void
    {
        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function placeOrder(array $overrides = [])
    {
        return $this->post(route('checkout.store'), [
            'recipient_name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'line1' => '12 Jalan Bunga', 'postcode' => '40100', 'city' => 'Shah Alam', 'state' => 'Selangor',
            'expected_total' => $this->expectedTotal(),
            'payment_method' => 'cod', // online payment is covered in HitPayPaymentTest
            ...$overrides,
        ]);
    }

    /**
     * The total the checkout page shows for the current cart, as the hidden field carries it.
     */
    private function expectedTotal(): string
    {
        $subtotal = collect(app(ShoppingCart::class)->lines())->sum(fn ($line) => $line->total());

        return number_format($subtotal + config('shop.shipping_fee'), 2, '.', '');
    }

    // --- Access & page ----------------------------------------------------

    public function test_guests_must_log_in_and_come_back_to_checkout_with_their_cart(): void
    {
        $this->addToCart($this->dunk);

        $this->get(route('checkout.create'))->assertRedirect(route('login'));
        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])
            ->assertRedirect(route('checkout.create'));

        $this->get(route('checkout.create'))->assertOk()->assertSee('Nike Dunk Low');
    }

    public function test_empty_cart_redirects_to_the_cart(): void
    {
        $this->actingAs($this->customer)
            ->get(route('checkout.create'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error');
    }

    public function test_form_is_prefilled_from_the_profile_and_shows_totals(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $this->addToCart($this->samba, 2);

        // No saved addresses yet: the manual form, with the account name and phone filled in.
        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee(['value="Aisyah Rahman"', 'value="012-345 6789"', 'name="address_id" value="new"'], false)
            ->assertSee(['RM 599.80', 'RM 10.00', 'RM 609.80'])
            ->assertSee('name="expected_total" value="609.80"', false);
    }

    public function test_shipping_details_are_validated(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);

        $this->placeOrder(['recipient_name' => '', 'phone' => '12345', 'line1' => '', 'postcode' => '401', 'city' => '', 'state' => 'Bangkok'])
            ->assertSessionHasErrors([
                'recipient_name',
                'phone' => 'Enter a Malaysian phone number, e.g. 012-345 6789.',
                'line1',
                'postcode' => 'Malaysian postcodes have 5 digits.',
                'city',
                'state' => 'Choose a state from the list.',
            ]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_common_malaysian_phone_formats_are_accepted(): void
    {
        $this->actingAs($this->customer);
        $this->samba->update(['stock' => 10]); // one pair per order below

        foreach (['0123456789', '012-345 6789', '011-1234 5678', '+60 12-345 6789', '+60123456789', '03-1234 5678'] as $phone) {
            $this->addToCart($this->samba);
            $this->placeOrder(['phone' => $phone])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('orders', 6);
    }

    // --- Placing the order -------------------------------------------------

    public function test_placing_an_order_creates_order_and_items_reduces_stock_and_empties_the_cart(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $this->addToCart($this->samba, 2);

        $response = $this->placeOrder();

        $order = Order::sole();
        $response->assertRedirect(route('checkout.complete', $order->order_number));

        $this->assertSame($this->customer->id, $order->user_id);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('609.80', $order->total);          // 300 + 2 × 149.90 + 10
        $this->assertSame('10.00', $order->shipping_fee);
        $this->assertSame('599.80', $order->subtotal);
        $this->assertSame('Aisyah Rahman', $order->shipping_name);
        $this->assertSame('012-345 6789', $order->shipping_phone);
        $this->assertMatchesRegularExpression('/^TS-\d{8}-[A-HJ-NP-Z2-9]{6}$/', $order->order_number);

        $this->assertEqualsCanonicalizing(
            [[$this->dunk->id, 1, '300.00'], [$this->samba->id, 2, '149.90']],
            $order->items->map(fn (OrderItem $item) => [$item->product_id, $item->quantity, $item->price])->all(),
        );

        $this->dunk->refresh();
        $this->samba->refresh();
        $this->assertSame(0, $this->dunk->stock);
        $this->assertSame(ProductStatus::Sold, $this->dunk->status);
        $this->assertSame(1, $this->samba->stock);
        $this->assertSame(ProductStatus::Available, $this->samba->status);

        $this->assertSame(0, app(ShoppingCart::class)->count());
    }

    public function test_prices_and_totals_from_the_browser_are_never_used(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);

        // Extra fields an attacker might add are ignored; only expected_total is compared.
        $this->placeOrder(['total' => '1.00', 'price' => '1.00', 'items' => [['product_id' => $this->dunk->id, 'price' => 1]]])
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('310.00', $order->total);
        $this->assertSame('300.00', $order->items->sole()->price);
    }

    public function test_a_lowered_expected_total_does_not_place_the_order(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);

        $this->placeOrder(['expected_total' => '1.00'])
            ->assertRedirect(route('checkout.create'))
            ->assertSessionHas('error', 'Prices changed since you opened checkout. Your new total is RM 310.00. Please review it before placing your order.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $this->dunk->fresh()->stock);
    }

    public function test_price_change_after_opening_checkout_asks_the_customer_to_review(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $shownTotal = $this->expectedTotal(); // 310.00, as rendered on the page

        $this->dunk->update(['price' => 320]); // admin edits the price meanwhile

        $this->placeOrder(['expected_total' => $shownTotal])
            ->assertRedirect(route('checkout.create'))
            ->assertSessionHas('error', 'Prices changed since you opened checkout. Your new total is RM 330.00. Please review it before placing your order.');

        $this->assertDatabaseCount('orders', 0);
        $this->get(route('checkout.create'))->assertSee('RM 330.00');
    }

    public function test_checkout_never_changes_the_account(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->samba);
        $this->placeOrder(['recipient_name' => 'Puan Aminah', 'phone' => '019-888 7777', 'save_address' => '1']);

        $this->customer->refresh();
        $this->assertSame('012-345 6789', $this->customer->phone, 'The account phone is managed on the profile.');
        $this->assertSame('Aisyah Rahman', $this->customer->name);
    }

    public function test_order_numbers_are_unique(): void
    {
        $this->actingAs($this->customer);

        foreach (range(1, 3) as $i) {
            $this->addToCart($this->samba);
            $this->placeOrder();
        }

        $this->assertSame(3, Order::distinct()->count('order_number'));
    }

    public function test_double_submit_does_not_create_a_second_order(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $expected = $this->expectedTotal();

        $this->placeOrder(['expected_total' => $expected]);
        $this->placeOrder(['expected_total' => $expected])
            ->assertRedirect(route('account.orders.index'));

        $this->assertDatabaseCount('orders', 1);
    }

    // --- Double purchase / availability -------------------------------------

    public function test_two_customers_cannot_buy_the_same_one_of_a_kind_pair(): void
    {
        $daniel = User::factory()->create();

        // Both put the same pair in their carts, and Daniel opens checkout.
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $this->actingAs($daniel);
        $this->addToCart($this->dunk);
        $danielsShownTotal = $this->expectedTotal();

        // Aisyah buys it first.
        $this->actingAs($this->customer);
        $this->placeOrder()->assertRedirect();

        // Daniel then submits the checkout page he already had open.
        $this->actingAs($daniel);
        $this->placeOrder(['recipient_name' => 'Daniel', 'expected_total' => $danielsShownTotal])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error', "Your order wasn't placed because your cart changed. Nothing was charged. Please review your cart and check out again.");

        $this->get(route('cart.index'))
            ->assertSee('Sorry, someone else just bought Nike Dunk Low (UK 8.5), so we removed it from your cart.')
            ->assertSee('See other UK 8.5 shoes');

        $this->assertSame(1, Order::count());
        $this->assertSame($this->customer->id, Order::sole()->user_id);
        $this->assertSame(0, $this->dunk->fresh()->stock, 'Stock never goes below zero.');
        $this->assertSame(0, app(ShoppingCart::class)->count(), "The sold pair is removed from Daniel's cart.");
    }

    public function test_checkout_is_all_or_nothing(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $this->addToCart($this->samba, 2);
        $expected = $this->expectedTotal();

        // The Dunk sells to someone else before this customer submits.
        $this->dunk->update(['stock' => 0, 'status' => 'sold']);

        $this->placeOrder(['expected_total' => $expected])->assertRedirect(route('cart.index'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(3, $this->samba->fresh()->stock, 'The other item is untouched.');
        $this->assertSame(2, app(ShoppingCart::class)->quantityOf($this->samba), 'The still-available item stays in the cart.');
    }

    public function test_not_enough_stock_for_the_quantity_blocks_the_order(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->samba, 3);
        $expected = $this->expectedTotal();

        $this->samba->update(['stock' => 2]); // one pair sold elsewhere

        $this->placeOrder(['expected_total' => $expected])->assertRedirect(route('cart.index'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, $this->samba->fresh()->stock);
        $this->assertSame(2, app(ShoppingCart::class)->quantityOf($this->samba), 'Quantity is clamped to what is left.');
    }

    public function test_confirmation_page_shows_the_order(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $this->placeOrder();
        $order = Order::sole();

        $this->get(route('checkout.complete', $order->order_number))
            ->assertOk()
            ->assertSee(['Thank you! Your order has been placed.', $order->order_number, 'Nike Dunk Low', 'RM 310.00'])
            ->assertSee(['Aisyah Rahman', '012-345 6789', '12 Jalan Bunga'])
            ->assertSee('Cash on Delivery.')
            ->assertSee('Please have RM 310.00 ready when your shoes arrive.');
    }

    public function test_admin_sees_the_recipient_and_shipping_fee(): void
    {
        $this->actingAs($this->customer);
        $this->addToCart($this->dunk);
        $this->placeOrder(['recipient_name' => 'Puan Aminah', 'phone' => '019-111 2222']);
        $order = Order::sole();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.orders.show', $order))
            ->assertSee(['Puan Aminah', '019-111 2222', 'RM 300.00', 'RM 10.00', 'RM 310.00']);
    }
}
