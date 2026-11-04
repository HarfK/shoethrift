<?php

namespace App\Services\Recommendations;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\DB;

/**
 * Logs what logged-in customers do with products (guests are never logged).
 * Rows are written after the surrounding transaction commits, so a rollback logs nothing.
 */
class Interactions
{
    /** Allowed values for ?src= on product links. */
    public const SOURCES = ['recommendation', 'for_you'];

    /** A repeat view of the same product within this time is not logged again (page refreshes). */
    public const VIEW_REPEAT_MINUTES = 30;

    public static function view(?User $user, Product $product, ?string $source = null): void
    {
        if (! $user) {
            return;
        }
        $source = in_array($source, self::SOURCES, true) ? $source : null;

        $seenRecently = UserInteraction::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('type', UserInteraction::VIEW)
            ->where('source', $source)
            ->where('created_at', '>=', now()->subMinutes(self::VIEW_REPEAT_MINUTES))
            ->exists();

        if (! $seenRecently) {
            self::log($user->id, $product->id, UserInteraction::VIEW, $source);
        }
    }

    public static function cart(?User $user, Product $product): void
    {
        if ($user) {
            self::log($user->id, $product->id, UserInteraction::CART);
        }
    }

    public static function purchase(int $userId, int $productId): void
    {
        self::log($userId, $productId, UserInteraction::PURCHASE);
    }

    /**
     * One purchase row per item of the order.
     */
    public static function purchaseOrder(Order $order): void
    {
        foreach ($order->items()->pluck('product_id') as $productId) {
            self::purchase($order->user_id, $productId);
        }
    }

    private static function log(int $userId, int $productId, string $type, ?string $source = null): void
    {
        DB::afterCommit(fn () => UserInteraction::create([
            'user_id' => $userId,
            'product_id' => $productId,
            'type' => $type,
            'source' => $source,
        ]));
    }
}
