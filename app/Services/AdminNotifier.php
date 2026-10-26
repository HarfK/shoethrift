<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\User;
use App\Notifications\AdminAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Sends the admin bell notifications to every admin (database notifications, sent synchronously).
 *
 * Every send is deferred with DB::afterCommit(): called inside a transaction it only goes out
 * once that transaction commits, so a rolled-back checkout or payment never notifies.
 */
class AdminNotifier
{
    public static function newOrder(Order $order): void
    {
        self::send('order', "New order {$order->order_number}", route('admin.orders.show', $order),
            'RM '.number_format((float) $order->total, 2).' · '.($order->payment_method?->label() ?? 'Unknown payment'));
    }

    public static function returnRequested(ReturnRequest $return): void
    {
        $return->loadMissing('order');

        self::send('return', "Return requested for {$return->order->order_number}", route('admin.returns.show', $return),
            $return->reason->label());
    }

    public static function lowStock(Product $product): void
    {
        self::send('low_stock', "Low stock: {$product->name} ({$product->size_label})", route('admin.products.edit', $product),
            $product->stock.' '.str('pair')->plural($product->stock).' left');
    }

    public static function paymentFailed(Order $order): void
    {
        self::send('payment_failed', "Payment failed for {$order->order_number}", route('admin.orders.show', $order),
            'The online payment didn\'t go through. The pairs are back on sale.');
    }

    public static function newMessage(ContactMessage $message): void
    {
        self::send('message', "New message from {$message->name}", route('admin.messages.show', $message),
            $message->topic->label().': '.str($message->message)->limit(80));
    }

    public static function newReview(Review $review): void
    {
        $stars = rtrim(rtrim((string) $review->rating, '0'), '.');
        self::send('review', "New {$stars}★ review on {$review->product->name}".($review->isFlagged() ? ' (flagged)' : ''),
            route('admin.reviews.index', ['search' => $review->product->name, 'visibility' => $review->isFlagged() ? 'flagged' : null]),
            (string) str($review->comment)->limit(80));
    }

    private static function send(string $kind, string $title, string $url, ?string $body): void
    {
        DB::afterCommit(function () use ($kind, $title, $url, $body) {
            Notification::send(
                User::where('role', UserRole::Admin)->get(),
                new AdminAlert($kind, $title, $url, $body),
            );
        });
    }
}
