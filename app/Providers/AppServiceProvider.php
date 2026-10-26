<?php

namespace App\Providers;

use App\Models\Category;
use App\Services\ShoppingCart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The only date formats used for display. Timestamps are stored in UTC and shown in
     * config('app.display_timezone') (Malaysia time).
     */
    public const DATE_FORMATS = [
        'shopDate' => 'j M Y',              // 26 Oct 2026
        'shopDateTime' => 'j M Y, g:i a',   // 26 Oct 2026, 3:45 pm
        'shopTime' => 'g:i a',              // 3:45 pm
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One cart per request, so reconcile() notices reach the layout.
        $this->app->scoped(ShoppingCart::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // $order->created_at->shopDateTime() etc.: converts a copy to display time, never the stored value.
        foreach (self::DATE_FORMATS as $name => $format) {
            Carbon::macro($name, function () use ($format): string {
                /** @var Carbon $this */
                return $this->copy()->setTimezone(config('app.display_timezone'))->format($format);
            });
        }

        // The shop footer lists every category; the header shows the cart count. Checking the cart
        // here means a customer hears about sold-out items on whatever shop page they open next.
        // Checkout flashes the notices of a failed order attempt as 'cart_notices'.
        View::composer('components.layouts.shop', function ($view) {
            $cart = $this->app->make(ShoppingCart::class);
            $cart->reconcile();

            $view->with([
                'footerCategories' => Category::orderBy('name')->get(['name', 'slug']),
                'cartCount' => $cart->count(),
                'cartNotices' => [...session('cart_notices', []), ...$cart->pullNotices()],
            ]);
        });
    }
}
