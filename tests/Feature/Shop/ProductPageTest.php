<?php

namespace Tests\Feature\Shop;

use App\Enums\ProductCondition;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->product = Product::factory()
            ->for(Category::factory()->create(['name' => 'Sneakers', 'slug' => 'sneakers']))
            ->create([
                'name' => 'Nike Air Jordan 1',
                'slug' => 'nike-air-jordan-1',
                'brand' => 'Nike',
                'size' => 8.5,
                'color' => 'Red/White/Black',
                'condition' => ProductCondition::Good,
                'price' => 420,
                'description' => 'Original laces, minor creasing.',
            ]);
    }

    private function addImage(Product $product, bool $primary): string
    {
        $path = UploadedFile::fake()->create('shoe.jpg', 10, 'image/jpeg')->store("products/{$product->id}", 'public');
        $product->images()->create(['image_path' => $path, 'is_primary' => $primary]);

        return $path;
    }

    public function test_shows_all_product_details(): void
    {
        $this->get(route('shop.show', 'nike-air-jordan-1'))
            ->assertOk()
            ->assertSee(['Nike Air Jordan 1', 'Nike', 'RM 420.00', 'UK 8.5', 'Red/White/Black', 'Good', 'Sneakers'])
            ->assertSee('Original laces, minor creasing.')
            ->assertSee('In stock — 1 pair left');
    }

    public function test_gallery_shows_the_primary_image_first(): void
    {
        $other = $this->addImage($this->product, primary: false);  // created first, lower id
        $primary = $this->addImage($this->product, primary: true);

        $this->get(route('shop.show', 'nike-air-jordan-1'))
            ->assertSeeInOrder(['id="photo-1" src="'.asset('storage/'.$primary), 'id="photo-2" src="'.asset('storage/'.$other)], false)
            ->assertSee('href="#photo-2"', false);
    }

    public function test_product_without_photos_shows_a_placeholder(): void
    {
        $this->get(route('shop.show', 'nike-air-jordan-1'))->assertSee('No photo yet');
    }

    public function test_guests_are_asked_to_log_in_and_return_to_the_product(): void
    {
        $returnPath = '/shop/nike-air-jordan-1';

        $this->get(route('shop.show', 'nike-air-jordan-1'))
            ->assertSee('Log in to buy')
            ->assertSee(e(route('login', ['return' => $returnPath])), false)
            ->assertDontSee('Add to cart');

        $customer = User::factory()->create();
        $this->get(route('login', ['return' => $returnPath]))->assertOk();
        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect(url($returnPath));
    }

    public function test_login_return_parameter_cannot_redirect_off_site(): void
    {
        $customer = User::factory()->create();

        foreach (['//evil.example', 'https://evil.example', '/\\evil.example'] as $return) {
            $this->get(route('login', ['return' => $return]));
            $this->post('/login', ['email' => $customer->email, 'password' => 'password'])
                ->assertRedirect(route('home'));
            $this->post('/logout');
        }
    }

    public function test_logged_in_customers_see_a_disabled_add_to_cart_button(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('shop.show', 'nike-air-jordan-1'))
            ->assertSee('Add to cart')
            ->assertSee('Cart opens soon.')
            ->assertDontSee('Log in to buy');
    }

    public function test_sold_products_show_a_sold_label_instead_of_buying(): void
    {
        $this->product->update(['status' => 'sold', 'stock' => 0]);

        $this->get(route('shop.show', 'nike-air-jordan-1'))
            ->assertOk()
            ->assertSee('Sold')
            ->assertSee('Browse other UK 8.5 shoes')
            ->assertDontSee('Add to cart')
            ->assertDontSee('Log in to buy')
            ->assertDontSee('In stock');
    }

    public function test_hidden_and_unknown_products_return_404(): void
    {
        $this->product->update(['status' => 'hidden']);

        $this->get(route('shop.show', 'nike-air-jordan-1'))->assertNotFound();
        $this->get(route('shop.show', 'no-such-shoe'))->assertNotFound();
    }

    public function test_product_cards_link_to_the_product_page(): void
    {
        $this->get(route('shop.index'))->assertSee(route('shop.show', 'nike-air-jordan-1'), false);
    }
}
