<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Moderation: hide (kept, but out of the shop) or delete (gone; the customer could review again).
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $visibility = in_array($request->query('visibility'), ['visible', 'hidden'], true) ? $request->query('visibility') : null;
        $rating = filter_var($request->query('rating'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) ?: null;
        $search = $request->string('search')->trim()->toString();

        $reviews = Review::query()
            ->with(['product', 'user', 'order'])
            ->when($visibility === 'visible', fn ($query) => $query->visible())
            ->when($visibility === 'hidden', fn ($query) => $query->whereNotNull('hidden_at'))
            ->when($rating, fn ($query) => $query->where('rating', $rating))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereHas('product', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('brand', 'like', "%{$search}%"))
                ->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.reviews.index', [
            'reviews' => $reviews,
            'filters' => ['visibility' => $visibility, 'rating' => $rating, 'search' => $search],
        ]);
    }

    public function toggle(Review $review): RedirectResponse
    {
        $review->forceFill(['hidden_at' => $review->isHidden() ? null : now()])->save();

        return back()->with('success', $review->isHidden()
            ? 'Review hidden. It no longer appears in the shop or in the product rating.'
            : 'Review is visible in the shop again.');
    }

    public function destroy(Review $review): RedirectResponse
    {
        $review->delete();

        return back()->with('success', 'Review deleted.');
    }
}
