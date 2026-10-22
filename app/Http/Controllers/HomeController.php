<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\View\View;

class HomeController extends Controller
{
    private const NEW_ARRIVALS = 8;

    public function __invoke(): View
    {
        return view('home', [
            'newArrivals' => Product::available()
                ->with(['primaryImage', 'category'])
                ->latest()
                ->latest('id')
                ->limit(self::NEW_ARRIVALS)
                ->get(),
            'categories' => Category::withCount(['products as available_count' => fn ($query) => $query->available()])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
