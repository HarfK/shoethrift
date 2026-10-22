<?php

namespace App\Providers;

use App\Models\Category;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The shop footer lists every category.
        View::composer('components.layouts.shop', function ($view) {
            $view->with('footerCategories', Category::orderBy('name')->get(['name', 'slug']));
        });
    }
}
