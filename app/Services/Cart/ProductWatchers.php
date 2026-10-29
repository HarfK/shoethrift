<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\ProductWatch;
use App\Notifications\ProductAvailableAgain;
use Illuminate\Support\Facades\DB;

/**
 * "Notify me when it's free": when a pair becomes free again (a cart hold ended, or an order was
 * cancelled, failed or refunded), each watcher gets one email and the watch is removed.
 * Runs after the surrounding transaction commits, so a rollback never emails anyone.
 */
class ProductWatchers
{
    /**
     * @param  list<int>  $productIds
     */
    public static function notifyIfFree(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        DB::afterCommit(function () use ($productIds) {
            $watched = ProductWatch::query()->whereIn('product_id', $productIds)->with(['user', 'product'])->get()->groupBy('product_id');

            foreach ($watched as $productId => $watches) {
                $product = $watches->first()->product;
                if (! $product?->isAvailable() || $product->stock - Reservations::heldByOthers($product->id, null) <= 0) {
                    continue; // still sold out or held by someone: keep watching
                }

                foreach ($watches as $watch) {
                    $watch->user?->notify(new ProductAvailableAgain($product));
                }
                ProductWatch::query()->whereIn('id', $watches->pluck('id'))->delete();
            }
        });
    }

    public static function isWatching(int $userId, int $productId): bool
    {
        return ProductWatch::query()->where('user_id', $userId)->where('product_id', $productId)->exists();
    }

    public static function watch(int $userId, Product $product): void
    {
        ProductWatch::firstOrCreate(['user_id' => $userId, 'product_id' => $product->id]);
    }

    public static function unwatch(int $userId, Product $product): void
    {
        ProductWatch::query()->where('user_id', $userId)->where('product_id', $product->id)->delete();
    }
}
