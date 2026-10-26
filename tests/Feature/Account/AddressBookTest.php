<?php

namespace Tests\Feature\Account;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressBookTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['name' => 'Aisyah Rahman', 'phone' => '012-345 6789']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return [
            'label' => 'Home',
            'recipient_name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'line1' => 'No. 12, Jalan Bunga Raya',
            'line2' => 'Taman Melati',
            'postcode' => '40100',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            ...$overrides,
        ];
    }

    private function addAddress(array $overrides = [])
    {
        return $this->actingAs($this->customer)->post(route('account.addresses.store'), $this->fields($overrides));
    }

    // --- Address book -----------------------------------------------------

    public function test_first_address_becomes_the_default(): void
    {
        $this->actingAs($this->customer)->get(route('account.addresses.index'))->assertSee("You haven't saved any addresses yet.", false);

        $this->addAddress()->assertRedirect(route('account.addresses.index'))->assertSessionHas('success', 'Address saved.');

        $address = Address::sole();
        $this->assertTrue($address->is_default);
        $this->assertSame($this->customer->id, $address->user_id);
        $this->assertSame(['No. 12, Jalan Bunga Raya', 'Taman Melati', '40100 Shah Alam', 'Selangor'], $address->lines());

        $this->actingAs($this->customer)->get(route('account.addresses.index'))
            ->assertSee(['Aisyah Rahman', 'Home', 'Default', '40100 Shah Alam']);
    }

    public function test_switching_the_default_keeps_exactly_one(): void
    {
        $this->addAddress(['label' => 'Home']);
        $this->addAddress(['label' => 'Office', 'make_default' => '1']);
        $this->addAddress(['label' => 'Parents']);

        $this->assertSame(['Office'], Address::where('is_default', true)->pluck('label')->all());

        $parents = Address::firstWhere('label', 'Parents');
        $this->actingAs($this->customer)->patch(route('account.addresses.default', $parents->id))->assertSessionHas('success');

        $this->assertSame(['Parents'], Address::where('is_default', true)->pluck('label')->all());
    }

    public function test_editing_an_address(): void
    {
        $this->addAddress();
        $address = Address::sole();

        $this->actingAs($this->customer)->get(route('account.addresses.edit', $address->id))->assertOk()->assertSee('value="Taman Melati"', false);

        $this->actingAs($this->customer)->put(route('account.addresses.update', $address->id), $this->fields(['line2' => '', 'city' => 'Klang', 'postcode' => '41200']))
            ->assertRedirect(route('account.addresses.index'));

        $address->refresh();
        $this->assertNull($address->line2);
        $this->assertSame('41200 Klang', $address->lines()[1]);
    }

    public function test_deleting_the_default_promotes_another(): void
    {
        $this->addAddress(['label' => 'Home']);
        $this->addAddress(['label' => 'Office']);
        $home = Address::firstWhere('label', 'Home');

        $this->actingAs($this->customer)->delete(route('account.addresses.destroy', $home->id))->assertSessionHas('success', 'Address deleted.');

        $this->assertModelMissing($home);
        $this->assertTrue(Address::sole()->is_default);
    }

    public function test_addresses_are_validated(): void
    {
        $this->addAddress(['recipient_name' => '', 'phone' => '123', 'line1' => '', 'postcode' => '4010', 'city' => '', 'state' => 'Texas'])
            ->assertSessionHasErrors([
                'recipient_name',
                'phone' => 'Enter a Malaysian phone number, e.g. 012-345 6789.',
                'line1' => 'Enter the street address.',
                'postcode' => 'Malaysian postcodes have 5 digits.',
                'city',
                'state' => 'Choose a state from the list.',
            ]);

        $this->assertSame(0, Address::count());
    }

    public function test_at_most_ten_addresses(): void
    {
        Address::factory()->count(10)->for($this->customer)->create();

        $this->actingAs($this->customer)->get(route('account.addresses.create'))->assertRedirect(route('account.addresses.index'));
        $this->addAddress()->assertSessionHas('error', 'You can save up to 10 addresses. Delete one to add another.');
        $this->assertSame(10, Address::count());
    }

    public function test_other_customers_addresses_are_404(): void
    {
        $theirs = Address::factory()->default()->create();

        $this->actingAs($this->customer)->get(route('account.addresses.edit', $theirs->id))->assertNotFound();
        $this->actingAs($this->customer)->put(route('account.addresses.update', $theirs->id), $this->fields())->assertNotFound();
        $this->actingAs($this->customer)->delete(route('account.addresses.destroy', $theirs->id))->assertNotFound();
        $this->actingAs($this->customer)->patch(route('account.addresses.default', $theirs->id))->assertNotFound();

        $this->assertModelExists($theirs);
    }

    // --- Checkout ----------------------------------------------------------

    private function checkout(array $fields)
    {
        $product = Product::factory()->create(['price' => 300, 'stock' => 1]);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => $product->id]);

        return $this->post(route('checkout.store'), [...$fields, 'expected_total' => '310.00', 'payment_method' => 'cod']);
    }

    public function test_checkout_offers_saved_addresses_with_the_default_selected(): void
    {
        $home = Address::factory()->for($this->customer)->default()->create(['recipient_name' => 'Aisyah at Home']);
        Address::factory()->for($this->customer)->create(['recipient_name' => 'Aisyah at Office']);
        $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_id' => Product::factory()->create(['stock' => 1])->id]);

        $this->get(route('checkout.create'))
            ->assertSee(['Aisyah at Home', 'Aisyah at Office', 'Use a different address', 'Manage your addresses'])
            ->assertSee('value="'.$home->id.'" class="mt-1 text-stone-900" checked', false);
    }

    public function test_order_keeps_a_snapshot_of_the_saved_address(): void
    {
        $address = Address::factory()->for($this->customer)->default()->create([
            'recipient_name' => 'Puan Aminah', 'phone' => '019-111 2222',
            'line1' => 'No. 5, Jalan Ampang', 'line2' => null, 'postcode' => '50450', 'city' => 'Kuala Lumpur', 'state' => 'W.P. Kuala Lumpur',
        ]);

        $this->checkout(['address_id' => $address->id])->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('Puan Aminah', $order->shipping_name);
        $this->assertSame('019-111 2222', $order->shipping_phone);
        $this->assertSame("No. 5, Jalan Ampang\n50450 Kuala Lumpur\nW.P. Kuala Lumpur", $order->shipping_address);

        // Editing and then deleting the address never touches the order.
        $this->actingAs($this->customer)->put(route('account.addresses.update', $address->id), $this->fields(['recipient_name' => 'Someone Else']));
        $this->actingAs($this->customer)->delete(route('account.addresses.destroy', $address->id));

        $order->refresh();
        $this->assertSame('Puan Aminah', $order->shipping_name);
        $this->assertSame("No. 5, Jalan Ampang\n50450 Kuala Lumpur\nW.P. Kuala Lumpur", $order->shipping_address);
    }

    public function test_manual_address_can_be_saved_to_the_book(): void
    {
        $this->checkout(['address_id' => 'new', ...$this->fields(['label' => null]), 'save_address' => '1'])->assertSessionHasNoErrors();

        $this->assertSame("No. 12, Jalan Bunga Raya\nTaman Melati\n40100 Shah Alam\nSelangor", Order::sole()->shipping_address);
        $saved = Address::sole();
        $this->assertTrue($saved->is_default);
        $this->assertSame('Shah Alam', $saved->city);
    }

    public function test_manual_address_without_saving(): void
    {
        $this->checkout(['address_id' => 'new', ...$this->fields(['label' => null]), 'save_address' => '0'])->assertSessionHasNoErrors();

        $this->assertSame(1, Order::count());
        $this->assertSame(0, Address::count());
    }

    public function test_someone_elses_address_cannot_be_used_at_checkout(): void
    {
        $theirs = Address::factory()->default()->create();

        $this->checkout(['address_id' => $theirs->id])
            ->assertSessionHasErrors(['address_id' => 'Choose one of your saved addresses.']);

        $this->assertSame(0, Order::count());
    }
}
