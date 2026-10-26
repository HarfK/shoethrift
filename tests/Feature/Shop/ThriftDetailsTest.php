<?php

namespace Tests\Feature\Shop;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\SoleCondition;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Material, insole length, sole condition, box, wear/defect notes and authenticity notes:
 * admin form, product page and shop filters.
 */
class ThriftDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->category = Category::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productFields(array $overrides = []): array
    {
        return [
            'name' => 'Nike Air Force 1',
            'brand' => 'Nike',
            'category_id' => $this->category->id,
            'condition' => ProductCondition::Good->value,
            'price' => '220',
            'size' => '9',
            'stock' => '1',
            'status' => ProductStatus::Available->value,
            'material' => 'Leather',
            'insole_length_cm' => '27.5',
            'sole_condition' => SoleCondition::Good->value,
            'box_included' => '1',
            'wear_notes' => 'Light creasing on the toe box.',
            'authenticity_notes' => 'Style code on the tongue label checked.',
            ...$overrides,
        ];
    }

    // --- Admin ------------------------------------------------------------------

    public function test_admin_form_has_the_thrift_fields(): void
    {
        $this->actingAs($this->admin)->get(route('admin.products.create'))
            ->assertSee(['Thrift details', 'Material', 'Insole length (cm)', 'Sole condition', 'Original box included', 'Defects or wear notes', 'Authenticity notes']);
    }

    public function test_admin_creates_and_edits_thrift_details(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->productFields())->assertSessionHasNoErrors();

        $product = Product::sole();
        $this->assertSame('Leather', $product->material);
        $this->assertSame('27.5', $product->insole_length_cm);
        $this->assertSame(SoleCondition::Good, $product->sole_condition);
        $this->assertTrue($product->box_included);
        $this->assertSame('Light creasing on the toe box.', $product->wear_notes);

        // Untick the box and clear the optional fields.
        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->productFields([
            'material' => '', 'insole_length_cm' => '', 'sole_condition' => '', 'box_included' => '0', 'wear_notes' => '', 'authenticity_notes' => '',
        ]))->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertNull($product->material);
        $this->assertNull($product->insole_length_cm);
        $this->assertNull($product->sole_condition);
        $this->assertFalse($product->box_included);
        $this->assertNull($product->wear_notes);
    }

    public function test_thrift_details_are_validated(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->productFields([
            'material' => 'Gold',
            'insole_length_cm' => '40',
            'sole_condition' => 'brand-new',
            'wear_notes' => str_repeat('a', 1001),
            'authenticity_notes' => str_repeat('b', 1001),
        ]))->assertSessionHasErrors([
            'material' => 'Choose a material from the list.',
            'insole_length_cm' => 'Enter the insole length in cm, between 10 and 35.',
            'sole_condition',
            'wear_notes',
            'authenticity_notes',
        ]);

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->productFields(['insole_length_cm' => '27.25']))
            ->assertSessionHasErrors(['insole_length_cm' => 'Use at most one decimal place, e.g. 27.5.']);

        $this->assertSame(0, Product::count());
    }

    // --- Product page ---------------------------------------------------------

    public function test_product_page_shows_the_details_and_notes(): void
    {
        $product = Product::factory()->create(['slug' => 'af1', 'material' => 'Suede', 'insole_length_cm' => 26.5,
            'sole_condition' => SoleCondition::Fair, 'box_included' => true,
            'wear_notes' => 'Small scuff on the left heel.', 'authenticity_notes' => 'Receipt from an authorised store seen.']);

        $this->get(route('shop.show', $product->slug))
            ->assertSee(['Material', 'Suede', 'Insole length', '26.5 cm', 'Fair: visible wear, plenty of grip left', 'Original box included'])
            ->assertSee(['Wear &amp; defects', 'Small scuff on the left heel.', 'Authenticity', 'Receipt from an authorised store seen.'], false)
            ->assertSee('compare it with 26.5 cm');
    }

    public function test_missing_details_say_not_specified_and_notes_are_hidden(): void
    {
        $product = Product::factory()->create(['slug' => 'plain', 'material' => null, 'insole_length_cm' => null,
            'sole_condition' => null, 'box_included' => false, 'wear_notes' => null, 'authenticity_notes' => null]);

        $response = $this->get(route('shop.show', $product->slug))->assertSee(['Not specified', 'No box']);
        $this->assertStringNotContainsString('data-wear-notes', $response->getContent());
        $this->assertStringNotContainsString('data-authenticity-notes', $response->getContent());
    }

    // --- Filters ----------------------------------------------------------------

    public function test_material_and_box_filters(): void
    {
        Product::factory()->create(['name' => 'Leather With Box', 'material' => 'Leather', 'box_included' => true, 'size' => 9]);
        Product::factory()->create(['name' => 'Leather No Box', 'material' => 'Leather', 'box_included' => false, 'size' => 10]);
        Product::factory()->create(['name' => 'Suede With Box', 'material' => 'Suede', 'box_included' => true, 'size' => 9]);

        $this->get(route('shop.index', ['material' => 'Leather']))
            ->assertSee(['Leather With Box', 'Leather No Box'])->assertDontSee('Suede With Box');
        $this->get(route('shop.index', ['box' => '1']))
            ->assertSee(['Leather With Box', 'Suede With Box'])->assertDontSee('Leather No Box');
        $this->get(route('shop.index', ['material' => 'Leather', 'box' => '1', 'size' => '9']))
            ->assertSee('Leather With Box')->assertDontSee(['Leather No Box', 'Suede With Box']);
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        Product::factory()->create(['name' => 'Any Pair', 'material' => 'Canvas']);

        $this->get(route('shop.index', ['material' => 'Gold', 'box' => 'yes']))->assertOk()->assertSee('Any Pair');
        $this->get(route('shop.index', ['material' => ['Leather']]))->assertOk()->assertSee('Any Pair');
    }

    public function test_material_options_list_only_materials_in_stock(): void
    {
        Product::factory()->create(['material' => 'Canvas']);
        Product::factory()->create(['material' => 'Suede', 'status' => ProductStatus::Sold, 'stock' => 0]);

        $this->get(route('shop.index'))
            ->assertViewHas('materials', ['Canvas'])
            ->assertSee(['All materials', 'With original box']);
    }
}
