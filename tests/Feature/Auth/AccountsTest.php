<?php

namespace Tests\Feature\Auth;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Remember me, phone uniqueness on the profile, and keeping existing / seeded accounts working.
 */
class AccountsTest extends TestCase
{
    use RefreshDatabase;

    // --- Remember me -------------------------------------------------------

    public function test_login_page_has_remember_me(): void
    {
        $this->get(route('login'))->assertSee(['Remember me', 'name="remember"'], false);
    }

    public function test_ticking_remember_me_sets_the_remember_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

        $response->assertCookie(Auth::guard()->getRecallerName());
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_without_remember_me_there_is_no_remember_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertCookieMissing(Auth::guard()->getRecallerName());
        $this->assertNull($user->fresh()->remember_token);
    }

    // --- Phone uniqueness on the profile --------------------------------------

    public function test_profile_cannot_take_another_accounts_phone(): void
    {
        User::factory()->create(['phone' => '019-888 7777']);
        $me = User::factory()->create(['phone' => '012-345 6789']);

        $this->actingAs($me)->patch(route('account.profile.update'), ['name' => $me->name, 'phone' => '+60 19-888 7777'])
            ->assertSessionHasErrors(['phone' => 'This phone number is already registered to another account.']);
        $this->assertSame('012-345 6789', $me->fresh()->phone);

        // Re-saving my own number in another format is fine.
        $this->actingAs($me)->patch(route('account.profile.update'), ['name' => $me->name, 'phone' => '0123456789'])
            ->assertSessionHasNoErrors();
        $this->assertSame('60123456789', $me->fresh()->phone_normalized);
    }

    public function test_the_database_also_refuses_duplicate_numbers(): void
    {
        User::factory()->create(['phone' => '012-345 6789']);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['phone' => '0123456789']);
    }

    // --- Existing and seeded accounts ------------------------------------------

    public function test_accounts_created_before_email_verification_are_marked_verified(): void
    {
        $old = User::factory()->unverified()->create(['created_at' => Carbon::parse('2026-10-20 08:00:00')]);
        $new = User::factory()->unverified()->create(['created_at' => Carbon::parse('2026-10-30 08:00:00')]);
        $verified = User::factory()->create(['created_at' => Carbon::parse('2026-10-01'), 'email_verified_at' => Carbon::parse('2026-10-02')]);

        (require database_path('migrations/2026_11_01_000022_verify_accounts_created_before_email_verification.php'))->up();

        $this->assertEquals(Carbon::parse('2026-10-20 08:00:00'), $old->fresh()->email_verified_at);
        $this->assertNull($new->fresh()->email_verified_at, 'Accounts made after verification existed still have to verify.');
        $this->assertEquals(Carbon::parse('2026-10-02'), $verified->fresh()->email_verified_at);
    }

    public function test_phone_backfill_skips_duplicates_instead_of_failing(): void
    {
        $migration = require database_path('migrations/2026_11_01_000021_add_phone_normalized_to_users_table.php');
        $migration->down();
        $first = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'phone' => '012-345 6789', 'created_at' => now(), 'updated_at' => now()]);
        $second = DB::table('users')->insertGetId(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'phone' => '+60123456789', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $this->assertSame('60123456789', DB::table('users')->where('id', $first)->value('phone_normalized'));
        $this->assertNull(DB::table('users')->where('id', $second)->value('phone_normalized'));
    }

    public function test_seeded_accounts_are_verified_except_the_demo_one(): void
    {
        $this->seed(UserSeeder::class);

        $users = User::all()->keyBy('email');
        foreach (['admin@thriftstore.test', 'customer@thriftstore.test'] as $email) {
            $this->assertTrue($users[$email]->hasVerifiedEmail(), $email);
            $this->assertTrue($users[$email]->hasVerifiedPhone(), $email);
        }
        $this->assertSame(0, User::where('email', '!=', 'unverified@thriftstore.test')->whereNull('email_verified_at')->count());
        $this->assertFalse($users['unverified@thriftstore.test']->hasVerifiedEmail());
        $this->assertFalse($users['unverified@thriftstore.test']->hasVerifiedPhone());
        $this->assertSame(User::whereNotNull('phone')->count(), User::whereNotNull('phone_normalized')->count());
    }

    public function test_seeded_customer_can_check_out_straight_away(): void
    {
        $this->seed(UserSeeder::class);
        $customer = User::firstWhere('email', 'customer@thriftstore.test');
        $this->actingAs($customer)->post(route('cart.items.store'), ['product_id' => Product::factory()->create(['stock' => 1])->id]);

        $this->get(route('checkout.create'))->assertOk()->assertSee('Recipient phone');
    }
}
