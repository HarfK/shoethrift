<?php

namespace App\Services\Cart;

use App\Models\CartItem;
use App\Services\CustomerNotifier;

/**
 * "Your hold expires soon": run by cart:release-expired every minute. Each cart line is warned at
 * most once: expiry_warned_at is set with a conditional update, so two runs at the same time
 * can't both send. (Holds are never extended, so one line = one hold.)
 */
class HoldWarnings
{
    public static function minutesBefore(): int
    {
        return max(1, (int) config('shop.cart.warn_minutes_before'));
    }

    /**
     * @return int number of warnings sent
     */
    public function send(): int
    {
        $lines = CartItem::query()
            ->where('reserved_until', '>', now())
            ->where('reserved_until', '<=', now()->addMinutes(self::minutesBefore()))
            ->whereNull('expiry_warned_at')
            ->with(['cart:id,user_id', 'product'])
            ->get();

        $sent = 0;
        foreach ($lines as $line) {
            $claimed = CartItem::query()->whereKey($line->id)->whereNull('expiry_warned_at')->update(['expiry_warned_at' => now()]);
            if ($claimed && $line->cart && $line->product) {
                CustomerNotifier::holdExpiringSoon($line);
                $sent++;
            }
        }

        return $sent;
    }
}
