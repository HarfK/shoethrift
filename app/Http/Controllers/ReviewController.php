<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Services\AdminNotifier;
use App\Services\Reviews\SaveReview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customers review pairs they bought (one review per product per order) and can edit or delete
 * their own reviews. Who may review is decided by Order::reviewableItems(); the unique
 * (order_id, product_id) index backs it up. Saving goes through SaveReview (media + flags).
 */
class ReviewController extends Controller
{
    public function create(Request $request, string $orderNumber, int $item): View|RedirectResponse
    {
        [$order, $orderItem] = $this->reviewable($request, $orderNumber, $item);

        if (! $orderItem) {
            return $this->notReviewable($order);
        }

        return view('account.reviews.form', ['order' => $order, 'item' => $orderItem, 'review' => new Review]);
    }

    public function store(ReviewRequest $request, SaveReview $saveReview, string $orderNumber, int $item): RedirectResponse
    {
        [$order, $orderItem] = $this->reviewable($request, $orderNumber, $item);

        if (! $orderItem) {
            return $this->notReviewable($order);
        }

        $review = (new Review)->forceFill([
            'user_id' => $request->user()->id,
            'order_id' => $order->id,
            'product_id' => $orderItem->product_id,
        ]);

        try {
            $this->save($request, $saveReview, $review);
        } catch (UniqueConstraintViolationException) {
            return $this->notReviewable($order); // a double submit lost the race
        }

        AdminNotifier::newReview($review->setRelation('product', $orderItem->product));

        return redirect()->route('account.orders.show', $order->order_number)
            ->with('success', "Thanks for reviewing {$orderItem->product->name}!");
    }

    public function edit(Request $request, Review $review): View
    {
        $this->authorizeOwner($request, $review);
        $review->load(['order', 'product.primaryImage', 'media']);

        return view('account.reviews.form', [
            'order' => $review->order,
            'item' => $review->order->items()->where('product_id', $review->product_id)->with('product.primaryImage')->firstOrFail(),
            'review' => $review,
        ]);
    }

    public function update(ReviewRequest $request, SaveReview $saveReview, Review $review): RedirectResponse
    {
        $this->authorizeOwner($request, $review);

        $this->save($request, $saveReview, $review);

        return redirect()->route('account.orders.show', $review->order->order_number)
            ->with('success', 'Your review was updated.'.($review->isHidden() ? ' It stays hidden until ShoeThrift checks it.' : ''));
    }

    public function destroy(Request $request, SaveReview $saveReview, Review $review): RedirectResponse
    {
        $this->authorizeOwner($request, $review);
        $orderNumber = $review->order->order_number;

        $saveReview->delete($review);

        return redirect()->route('account.orders.show', $orderNumber)->with('success', 'Your review was deleted.');
    }

    private function save(ReviewRequest $request, SaveReview $saveReview, Review $review): void
    {
        $saveReview->handle(
            $review,
            (float) $request->validated('rating'),
            (int) $request->validated('condition_rating'),
            $request->validated('comment'),
            $request->file('photos', []),
            $request->file('video'),
            $request->videoDuration,
            $request->removeIds(),
        );
    }

    /**
     * Only the author may edit or delete; anyone else gets a 404 (like other customers' orders).
     */
    private function authorizeOwner(Request $request, Review $review): void
    {
        abort_unless($review->user_id === $request->user()->id, 404);
    }

    /**
     * The customer's own order (404 otherwise), and the item if it belongs to that order and
     * may still be reviewed (null otherwise).
     *
     * @return array{0: Order, 1: ?OrderItem}
     */
    private function reviewable(Request $request, string $orderNumber, int $itemId): array
    {
        $order = $request->user()->orders()->where('order_number', $orderNumber)->firstOrFail();
        abort_unless($order->items()->whereKey($itemId)->exists(), 404);

        $item = $order->reviewableItems()->firstWhere('id', $itemId);
        $item?->load('product.primaryImage');

        return [$order, $item];
    }

    private function notReviewable(Order $order): RedirectResponse
    {
        return redirect()->route('account.orders.show', $order->order_number)->with('error', $order->allowsReviews()
            ? 'You have already reviewed this pair for this order.'
            : 'You can review pairs once your order is paid or delivered.');
    }
}
