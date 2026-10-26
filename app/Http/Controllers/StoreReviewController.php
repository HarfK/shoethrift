<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Services\Reviews\ReviewStats;
use Illuminate\View\View;

/**
 * Store-level reviews page: every visible review, newest first, with the store's average,
 * total and 5-to-1 breakdown (same buckets as the product page, see Review::breakdown()).
 */
class StoreReviewController extends Controller
{
    private const PER_PAGE = 15;

    public function __invoke(): View
    {
        return view('reviews.index', [
            'summary' => Review::storeSummary(),
            'breakdown' => Review::breakdown(ReviewStats::publicReviews()),
            // Reviewer as id + name only: no email/phone/address reaches the page.
            'reviews' => ReviewStats::publicReviews()
                ->with(['user:id,name', 'product.primaryImage', 'visibleMedia'])
                ->latest()
                ->latest('id')
                ->paginate(self::PER_PAGE),
        ]);
    }
}
