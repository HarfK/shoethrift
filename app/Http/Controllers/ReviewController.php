<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Services\AdminNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customers review pairs they bought, one review per product per order. Who may review is
 * decided by Order::reviewableItems(); the unique (order_id, product_id) index backs it up.
 */
class ReviewController extends Controller
{
    public function create(Request $request, string $orderNumber, int $item): View|RedirectResponse
    {
        [$order, $orderItem] = $this->reviewable($request, $orderNumber, $item);

        if (! $orderItem) {
            return $this->notReviewable($order);
        }

        return view('account.reviews.create', ['order' => $order, 'item' => $orderItem]);
    }

    public function store(Request $request, string $orderNumber, int $item): RedirectResponse
    {
        [$order, $orderItem] = $this->reviewable($request, $orderNumber, $item);

        if (! $orderItem) {
            return $this->notReviewable($order);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:'.Review::MIN_COMMENT, 'max:'.Review::MAX_COMMENT],
        ], [
            'rating.required' => 'Choose a star rating.',
            'rating.between' => 'Choose between 1 and 5 stars.',
        ]);

        $review = new Review(['rating' => (int) $data['rating'], 'comment' => trim($data['comment'])]);
        $review->forceFill([
            'user_id' => $request->user()->id,
            'order_id' => $order->id,
            'product_id' => $orderItem->product_id,
        ]);

        try {
            $review->save();
        } catch (UniqueConstraintViolationException) {
            return $this->notReviewable($order); // a double submit lost the race
        }

        AdminNotifier::newReview($review->setRelation('product', $orderItem->product));

        return redirect()->route('account.orders.show', $order->order_number)
            ->with('success', "Thanks for reviewing {$orderItem->product->name}!");
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
