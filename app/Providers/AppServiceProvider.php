<?php

namespace App\Providers;

use App\Models\Category;
use App\Services\ShoppingCart;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
        // The shop footer lists every category; the header shows the cart count. Checking the cart
        // here means a customer hears about sold-out items on whatever shop page they open next.
        View::composer('components.layouts.shop', function ($view) {
            $cart = $this->app->make(ShoppingCart::class);
            $cart->reconcile();

            $view->with([
                'footerCategories' => Category::orderBy('name')->get(['name', 'slug']),
                'cartCount' => $cart->count(),
                'cartNotices' => $cart->pullNotices(),
            ]);
        });
    }
}
