<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Services\Recommendations\Recommender;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    private const NEW_ARRIVALS = 8;

    public function __invoke(Request $request, Recommender $recommender): View
    {
        return view('home', [
            // "For you": logged-in customers only, from their own preferences and history.
            'forYou' => $request->user() ? $recommender->forYou($request->user()) : null,
            'newArrivals' => Product::available()
                ->with(['primaryImage', 'category'])
                ->withRatingSummary()
                ->withHeldByOthers(auth()->id())
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
