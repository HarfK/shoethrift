<?php

namespace App\Services;

use App\Enums\ReturnStatus;
use App\Models\CartItem;
use App\Models\CartRelease;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Notifications\CustomerAlert;
use Illuminate\Support\Facades\DB;

/**
 * Sends the customer bell notifications (database notifications). Like AdminNotifier, every send
 * waits for the surrounding transaction to commit, so a rollback never notifies.
 */
class CustomerNotifier
{
    public static function orderPlaced(Order $order): void
    {
        self::send($order->user_id, 'order', "Your order {$order->order_number} has been placed.", self::orderUrl($order),
            'Pay in cash when it arrives.');
    }

    public static function orderPaid(Order $order): void
    {
        self::send($order->user_id, 'order', "Your order {$order->order_number} has been paid.", self::orderUrl($order),
            'We will ship it soon.');
    }

    public static function orderStatusChanged(Order $order): void
    {
        self::send($order->user_id, 'order', "Your order {$order->order_number} is now {$order->statusLabel()}.", self::orderUrl($order));
    }

    public static function returnDecided(ReturnRequest $return): void
    {
        $return->loadMissing('order');
        $approved = $return->status === ReturnStatus::Approved;

        self::send($return->order->user_id, 'return',
            "Your return request for order {$return->order->order_number} was ".($approved ? 'approved' : 'rejected').'.',
            self::orderUrl($return->order),
            $return->admin_note);
    }

    public static function holdExpiringSoon(CartItem $line): void
    {
        $line->loadMissing(['cart', 'product']);

        self::send($line->cart->user_id, 'hold', "Your hold on {$line->product->name} ({$line->product->size_label}) expires soon.",
            route('cart.index', absolute: false),
            'Check out before '.$line->reserved_until->shopTime().' to keep it.');
    }

    public static function holdExpired(CartRelease $release): void
    {
        $release->loadMissing('product');

        self::send($release->user_id, 'hold', "Your hold on {$release->product_name} has expired.",
            $release->product ? route('shop.show', $release->product->slug, absolute: false) : route('cart.index', absolute: false),
            'It was removed from your cart.');
    }

    private static function orderUrl(Order $order): string
    {
        return route('account.orders.show', $order->order_number, absolute: false);
    }

    private static function send(int $userId, string $kind, string $title, string $url, ?string $body = null): void
    {
        DB::afterCommit(function () use ($userId, $kind, $title, $url, $body) {
            User::find($userId)?->notify(new CustomerAlert($kind, $title, $url, $body));
        });
    }
}
