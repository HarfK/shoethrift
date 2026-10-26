<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use Illuminate\View\View;

class HomeController extends Controller
{
    private const NEW_ARRIVALS = 8;

    public function __invoke(): View
    {
        return view('home', [
            'newArrivals' => Product::available()
                ->with(['primaryImage', 'category'])
                ->withRatingSummary()
                ->latest()
                ->latest('id')
                ->limit(self::NEW_ARRIVALS)
                ->get(),
            'storeRating' => Review::storeSummary(),
            'categories' => Category::withCount(['products as available_count' => fn ($query) => $query->available()])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
