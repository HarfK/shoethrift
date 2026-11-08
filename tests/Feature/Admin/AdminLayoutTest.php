<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shared admin layout: the sidebar is a full-height sticky column on md+ screens, with the
 * account block (name, View store, Log out) pinned at the bottom on every admin page.
 */
class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return array_map(fn (string $route) => [$route], [
            'Dashboard' => 'admin.dashboard',
            'Products' => 'admin.products.index',
            'Categories' => 'admin.categories.index',
            'Orders' => 'admin.orders.index',
            'Reports' => 'admin.reports.index',
            'Reservations' => 'admin.reservations.index',
            'Returns' => 'admin.returns.index',
            'Reviews' => 'admin.reviews.index',
            'Messages' => 'admin.messages.index',
            'Revenue forecast' => 'admin.insights.forecast', // ML API not configured in tests: renders the offline note
            'Restock' => 'admin.insights.restock',
            'ML status' => 'admin.insights.status',
        ]);
    }

    #[DataProvider('pages')]
    public function test_every_admin_page_has_the_pinned_account_block(string $route): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Store Admin']);

        $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

        // Sidebar: sticky full-height column; the menu scrolls on its own; the account block is pinned at the bottom.
        $this->assertMatchesRegularExpression('/<aside class="[^"]*\bmd:sticky md:top-0 md:h-screen\b[^"]*" data-admin-sidebar>/', $html);
        $this->assertMatchesRegularExpression('/<nav class="[^"]*\bmd:min-h-0 md:flex-1\b[^"]*md:overflow-y-auto[^"]*" aria-label="Admin">/', $html);
        $this->assertMatchesRegularExpression('/<div class="[^"]*\bshrink-0\b[^"]*\bmd:mt-auto\b[^"]*" data-sidebar-account>/', $html);

        // The account block sits after the menu and holds the name, View store and Log out.
        $account = str($html)->after('data-sidebar-account>')->before('</aside>')->toString();
        $this->assertGreaterThan(strpos($html, 'aria-label="Admin"'), strpos($html, 'data-sidebar-account'));
        $this->assertStringContainsString('Store Admin', $account);
        $this->assertStringContainsString('href="'.route('home').'"', $account);
        $this->assertStringContainsString('>View store</a>', $account);
        $this->assertStringContainsString('action="'.route('logout').'"', $account);
        $this->assertStringContainsString('>Log out</button>', $account);
    }

    public function test_small_screens_still_get_view_store_and_log_out_below_the_page(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->getContent();

        $main = str($html)->after('<main')->before('</main>')->toString();
        $this->assertStringContainsString('md:hidden', $main);
        $this->assertStringContainsString('>View store</a>', $main);
        $this->assertStringContainsString('>Log out</button>', $main);
    }
}
