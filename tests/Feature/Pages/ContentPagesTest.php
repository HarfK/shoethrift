<?php

namespace Tests\Feature\Pages;

use App\Enums\ContactTopic;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'returns' => ['pages.returns', 'Return & Refund Policy'],
            'shipping' => ['pages.shipping', 'Shipping Policy'],
            'faq' => ['pages.faq', 'FAQ'],
            'terms' => ['pages.terms', 'Terms & Conditions'],
            'privacy' => ['pages.privacy', 'Privacy Policy'],
            'customer service' => ['pages.customer-service', 'Customer Service'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_loads_and_is_linked_in_the_footer(string $route, string $title): void
    {
        $this->get(route($route))->assertOk()->assertSee($title);

        $this->get(route('home'))->assertSee(route($route), false);
    }

    public function test_policies_use_the_configured_numbers(): void
    {
        config(['shop.returns.window_days' => 10, 'shop.shipping_fee' => 12.5]);

        $this->get(route('pages.returns'))->assertSee('within 10 days of delivery', false);
        $this->get(route('pages.shipping'))->assertSee('RM 12.50');
        $this->get(route('pages.faq'))->assertSee('Yes, within 10 days of delivery.');
    }

    // --- Contact form -----------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sendMessage(array $overrides = [])
    {
        return $this->post(route('pages.customer-service.store'), [
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah@example.com',
            'topic' => 'order',
            'order_number' => 'ts-20261026-abcdef',
            'message' => 'My parcel has not arrived yet, can you check?',
            ...$overrides,
        ]);
    }

    public function test_guests_can_send_a_message(): void
    {
        $this->sendMessage()
            ->assertRedirect(route('pages.customer-service'))
            ->assertSessionHas('success', 'Thanks, we got your message. We reply within 1 to 2 working days.');

        $message = ContactMessage::sole();
        $this->assertSame('Aisyah Rahman', $message->name);
        $this->assertSame(ContactTopic::Order, $message->topic);
        $this->assertSame('TS-20261026-ABCDEF', $message->order_number);
        $this->assertNull($message->user_id);
        $this->assertNull($message->read_at);
    }

    public function test_logged_in_customers_get_their_details_filled_in_and_linked(): void
    {
        $customer = User::factory()->create(['name' => 'Daniel Lee', 'email' => 'daniel@example.com']);

        $this->actingAs($customer)->get(route('pages.customer-service'))
            ->assertSee('value="Daniel Lee"', false)
            ->assertSee('value="daniel@example.com"', false);

        $this->sendMessage(['name' => 'Daniel Lee', 'email' => 'daniel@example.com']);
        $this->assertSame($customer->id, ContactMessage::sole()->user_id);
    }

    public function test_message_is_validated(): void
    {
        $this->sendMessage(['email' => 'not-an-email', 'topic' => 'complaint', 'order_number' => '12345', 'message' => 'short'])
            ->assertSessionHasErrors(['email', 'topic', 'order_number', 'message']);

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_bots_filling_the_honeypot_are_ignored(): void
    {
        $this->sendMessage(['website' => 'http://spam.example'])->assertSessionHas('success');

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_sending_is_rate_limited(): void
    {
        RateLimiter::clear('');

        foreach (range(1, 5) as $i) {
            $this->sendMessage()->assertRedirect();
        }

        $this->sendMessage()->assertStatus(429);
        $this->assertSame(5, ContactMessage::count());
    }

    // --- Admin messages ---------------------------------------------------

    public function test_admin_reads_and_resolves_messages(): void
    {
        $admin = User::factory()->admin()->create();
        $message = ContactMessage::factory()->create(['name' => 'Aisyah Rahman', 'message' => 'Where is my parcel please?']);
        ContactMessage::factory()->resolved()->create(['name' => 'Old Resolved']);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('1 need attention');

        $this->actingAs($admin)->get(route('admin.messages.index'))
            ->assertSee(['Aisyah Rahman', 'New'])
            ->assertDontSee('Old Resolved');

        $this->actingAs($admin)->get(route('admin.messages.show', $message))
            ->assertSee(['Where is my parcel please?', 'Reply by email', 'mailto:'.$message->email], false);
        $this->assertNotNull($message->fresh()->read_at);

        $this->actingAs($admin)->patch(route('admin.messages.resolve', $message))
            ->assertRedirect(route('admin.messages.index'));
        $this->assertNotNull($message->fresh()->resolved_at);

        $this->actingAs($admin)->get(route('admin.messages.index', ['filter' => 'resolved']))
            ->assertSee(['Aisyah Rahman', 'Old Resolved']);
    }

    public function test_customers_cannot_see_admin_messages(): void
    {
        $message = ContactMessage::factory()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->get(route('admin.messages.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.messages.show', $message))->assertForbidden();
        $this->actingAs($customer)->patch(route('admin.messages.resolve', $message))->assertForbidden();
    }
}
