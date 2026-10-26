<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\ReviewMedia;
use App\Services\Reviews\SaveReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Moderation: hide (kept, but out of the shop) or delete (gone; the customer could review again)
 * whole reviews or single photos/videos, and work through flagged reviews (Review::FLAG_LABELS).
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $visibility = in_array($request->query('visibility'), ['visible', 'hidden', 'flagged'], true) ? $request->query('visibility') : null;
        // Star rows like the breakdown: 4 = 4.0 to 4.5 (1 = 0.5 to 1.5).
        $rating = filter_var($request->query('rating'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) ?: null;
        $search = $request->string('search')->trim()->toString();

        $reviews = Review::query()
            ->with(['product', 'user', 'order', 'media'])
            ->when($visibility === 'visible', fn ($query) => $query->visible())
            ->when($visibility === 'hidden', fn ($query) => $query->whereNotNull('hidden_at'))
            ->when($visibility === 'flagged', fn ($query) => $query->whereNotNull('flagged_at'))
            ->when($rating, fn ($query) => $query->whereBetween('rating', $rating === 1 ? [0.5, 1.5] : [$rating, $rating + 0.5]))
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
            'flaggedCount' => Review::whereNotNull('flagged_at')->count(),
        ]);
    }

    public function toggle(Review $review): RedirectResponse
    {
        $review->forceFill(['hidden_at' => $review->isHidden() ? null : now()])->save();

        return back()->with('success', $review->isHidden()
            ? 'Review hidden. It no longer appears in the shop or in the product rating.'
            : 'Review is visible in the shop again.');
    }

    public function destroy(Review $review, SaveReview $saveReview): RedirectResponse
    {
        $saveReview->delete($review);

        return back()->with('success', 'Review deleted.');
    }

    public function markChecked(Review $review): RedirectResponse
    {
        $review->markChecked();

        return back()->with('success', 'Marked as checked. The review is no longer flagged.');
    }

    /**
     * Hide or unhide one photo or video. Hiding moves the file off the public disk.
     */
    public function toggleMedia(ReviewMedia $media): RedirectResponse
    {
        $media->isHidden() ? $media->unhide() : $media->hide();

        return back()->with('success', ($media->isVideo() ? 'Video' : 'Photo').($media->isHidden() ? ' hidden.' : ' visible again.'));
    }

    public function destroyMedia(ReviewMedia $media): RedirectResponse
    {
        $media->deleteWithFile();

        return back()->with('success', ($media->isVideo() ? 'Video' : 'Photo').' deleted.');
    }
}
