<?php

namespace App\Http\Controllers;

use App\Enums\ReturnReason;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestPhoto;
use App\Services\AdminNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Customer side of returns: request a return on an eligible order, and view its photos.
 * Eligibility rules live in Order::returnUnavailableReason().
 */
class ReturnRequestController extends Controller
{
    public const MAX_PHOTO_KB = 4096;

    public function create(Request $request, string $orderNumber): View|RedirectResponse
    {
        $order = $this->ownOrder($request, $orderNumber);

        if ($reason = $order->returnUnavailableReason()) {
            return redirect()->route('account.orders.show', $order->order_number)->with('error', $reason);
        }

        return view('account.returns.create', [
            'order' => $order,
            'reasons' => ReturnReason::cases(),
        ]);
    }

    public function store(Request $request, string $orderNumber): RedirectResponse
    {
        $order = $this->ownOrder($request, $orderNumber);

        if ($reason = $order->returnUnavailableReason()) {
            return redirect()->route('account.orders.show', $order->order_number)->with('error', $reason);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer', Rule::in($order->items->pluck('id')->all())],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.config('shop.returns.max_photos')],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_PHOTO_KB],
        ], [
            'items.required' => 'Choose at least one pair to return.',
            'photos.max' => 'You can add up to '.config('shop.returns.max_photos').' photos.',
            'photos.*.image' => 'Each photo must be an image.',
            'photos.*.mimes' => 'Photos must be JPG, PNG or WebP.',
            'photos.*.max' => 'Each photo must be 4 MB or smaller.',
        ]);

        $stored = [];

        try {
            DB::transaction(function () use ($request, $order, $data, &$stored) {
                // Lock the order so a double-submit can't create two requests (order_id is also unique).
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($locked->returnRequest()->exists()) {
                    abort(redirect()->route('account.orders.show', $order->order_number)
                        ->with('error', 'A return has already been requested for this order.'));
                }

                $return = ReturnRequest::create([
                    'order_id' => $order->id,
                    'user_id' => $request->user()->id,
                    'reason' => $data['reason'],
                    'details' => trim($data['details']),
                ]);

                foreach ($order->items->whereIn('id', array_map('intval', $data['items'])) as $item) {
                    $return->items()->create(['order_item_id' => $item->id, 'quantity' => $item->quantity]);
                }

                foreach ($request->file('photos', []) as $photo) {
                    $path = $photo->store("returns/{$return->id}", ReturnRequestPhoto::DISK);
                    $stored[] = $path;
                    $return->photos()->create(['path' => $path]);
                }

                AdminNotifier::returnRequested($return); // sent after commit
            });
        } catch (Throwable $e) {
            Storage::disk(ReturnRequestPhoto::DISK)->delete($stored);

            throw $e;
        }

        return redirect()->route('account.orders.show', $order->order_number)
            ->with('success', "Your return request was sent. We'll review it within ".config('shop.support.reply_within').'.');
    }

    /**
     * A return photo, for the customer who sent it or an admin. Anyone else gets 404.
     */
    public function photo(Request $request, ReturnRequestPhoto $photo): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $photo->returnRequest->user_id === $user->id, 404);

        return Storage::disk(ReturnRequestPhoto::DISK)->response($photo->path);
    }

    private function ownOrder(Request $request, string $orderNumber): Order
    {
        return $request->user()->orders()
            ->where('order_number', $orderNumber)
            ->with('items.product')
            ->firstOrFail();
    }
}
