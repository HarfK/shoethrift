<?php

namespace App\Services\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\PointTransactionType as Type;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\Checkout\PlaceOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loyalty points: the only place that writes the point_transactions ledger. Rules in config/loyalty.php.
 *
 * Safety: every write runs in a transaction that first locks the customer's users row (SELECT ... FOR UPDATE),
 * then expires due points, then reads the ledger balance. Two checkouts redeeming at once therefore queue on
 * the lock, and the second sees the reduced balance: the balance can never go negative or be spent twice.
 *
 * FIFO: rows that add points are lots (expires_at, remaining). Redeeming, taking back and expiring use the
 * lots that expire first. Invariant: a customer's latest balance_after = the sum of `remaining` of their lots.
 *
 * Hooked into the order flow by App\Observers\OrderPointsObserver (completed -> earn; cancelled / failed ->
 * return redeemed points; refunds -> take earned points back).
 */
class PointsService
{
    // --- rules ---------------------------------------------------------------------------------------

    public function blockPoints(): int
    {
        return max(1, (int) config('loyalty.redeem_block_points'));
    }

    public function minPoints(): int
    {
        return max($this->blockPoints(), (int) config('loyalty.redeem_min_points'));
    }

    /** RM value of `$points` (whole blocks), as a "12.50" string. */
    public function discountFor(int $points): string
    {
        $blocks = intdiv(max(0, $points), $this->blockPoints());

        return PlaceOrder::fromSen($blocks * PlaceOrder::toSen(config('loyalty.redeem_block_value')));
    }

    /** Exact RM value of any number of points (the shop's liability; customers can only use whole blocks: discountFor()). */
    public function valueOf(int $points): string
    {
        return PlaceOrder::fromSen((int) floor($points * PlaceOrder::toSen(config('loyalty.redeem_block_value')) / $this->blockPoints()));
    }

    /** Points earned for an item subtotal: earn_per_ringgit per whole RM1, rounded down. */
    public function pointsForSubtotal(float|string $itemSubtotal): int
    {
        return intdiv(max(0, PlaceOrder::toSen($itemSubtotal)) * (int) config('loyalty.earn_per_ringgit'), 100);
    }

    /** The cap as a whole percentage of the item subtotal (config loyalty.redeem_max_share), e.g. 20. */
    public function maxSharePercent(): int
    {
        return (int) round(100 * (float) config('loyalty.redeem_max_share'));
    }

    /** The largest discount allowed on this item subtotal, in sen. */
    private function capSen(float|string $itemSubtotal): int
    {
        return (int) floor(PlaceOrder::toSen($itemSubtotal) * (float) config('loyalty.redeem_max_share'));
    }

    /** The rules in one line, all from config: "100 points = RM1 off, up to 20% of the items, minimum 500 points." */
    public function ruleText(): string
    {
        $value = (float) config('loyalty.redeem_block_value');
        $rm = 'RM'.number_format($value, fmod($value, 1.0) == 0.0 ? 0 : 2);

        return number_format($this->blockPoints())." points = {$rm} off, up to {$this->maxSharePercent()}% of the items, minimum "
            .number_format($this->minPoints()).' points.';
    }

    /** Most points (whole steps) that may be used on an order with this item subtotal; 0 if under the minimum. */
    public function maxRedeemable(int $balance, float|string $itemSubtotal): int
    {
        $block = $this->blockPoints();
        $capBlocks = intdiv($this->capSen($itemSubtotal), max(1, PlaceOrder::toSen(config('loyalty.redeem_block_value'))));
        $max = min(intdiv(max(0, $balance), $block), $capBlocks) * $block;

        return $max >= $this->minPoints() ? $max : 0;
    }

    /** Why `$points` can't be used on this order (customer-readable), or null if it can. 0 points is always fine. */
    public function redeemError(int $points, int $balance, float|string $itemSubtotal): ?string
    {
        $block = $this->blockPoints();

        return match (true) {
            $points === 0 => null,
            $points < $this->minPoints() => 'You can use points from '.number_format($this->minPoints()).' points.',
            $points % $block !== 0 => 'Use points in steps of '.number_format($block).' (e.g. '.number_format($this->minPoints()).', '.number_format($this->minPoints() + $block).').',
            $points > $balance => 'You only have '.number_format($balance).' points.',
            PlaceOrder::toSen($this->discountFor($points)) > $this->capSen($itemSubtotal) => $this->capMessage($itemSubtotal),
            default => null,
        };
    }

    private function capMessage(float|string $itemSubtotal): string
    {
        $allowed = $this->maxRedeemable(PHP_INT_MAX, $itemSubtotal);

        return "Points can pay for at most {$this->maxSharePercent()}% of the items (RM ".number_format($this->capSen($itemSubtotal) / 100, 2).'), '
            .($allowed > 0 ? 'so up to '.number_format($allowed).' points on this order.' : 'which is less than the minimum, so points can\'t be used on this order.');
    }

    // --- reading -------------------------------------------------------------------------------------

    /** Points the customer can use now (lots not yet expired). */
    public function balance(User|int $user, ?CarbonInterface $at = null): int
    {
        return (int) PointTransaction::query()->where('user_id', $this->id($user))->openLots()
            ->where('expires_at', '>', $at ?? now())->sum('remaining');
    }

    /** Points that expire within the next `$days` days (default config loyalty.expiring_soon_days). */
    public function expiringSoon(User|int $user, ?int $days = null): int
    {
        return (int) PointTransaction::query()->where('user_id', $this->id($user))->openLots()
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($days ?? (int) config('loyalty.expiring_soon_days')))
            ->sum('remaining');
    }

    /** Earliest expiry date among the customer's open lots, or null. */
    public function nextExpiry(User|int $user): ?Carbon
    {
        $at = PointTransaction::query()->where('user_id', $this->id($user))->openLots()->where('expires_at', '>', now())->min('expires_at');

        return $at ? Carbon::parse($at) : null;
    }

    /** All customers' usable points (the shop's outstanding points). */
    public function outstanding(): int
    {
        return (int) PointTransaction::query()->openLots()->where('expires_at', '>', now())->sum('remaining');
    }

    public function earnedFor(Order $order): int
    {
        return (int) PointTransaction::query()->where('order_id', $order->id)->where('type', Type::Earn)->sum('points');
    }

    public function reversedFor(Order $order): int
    {
        return -(int) PointTransaction::query()->where('order_id', $order->id)->where('type', Type::ReverseEarn)->sum('points');
    }

    // --- writing -------------------------------------------------------------------------------------

    /** Credit points for a completed order (once). Dated `$at` (default now); expires expiry_months later. */
    public function earnFor(Order $order, ?CarbonInterface $at = null): ?PointTransaction
    {
        $at = Carbon::instance($at ?? now());

        return DB::transaction(function () use ($order, $at) {
            $this->lock($order->user_id);
            if (PointTransaction::query()->where('order_id', $order->id)->where('type', Type::Earn)->exists()) {
                return null;
            }
            $points = $this->pointsForSubtotal($order->subtotal);
            if ($points <= 0) {
                return null;
            }
            $this->expireDueLocked($order->user_id, $at);

            return $this->addLot($order->user_id, Type::Earn, $points, "Order {$order->order_number}", $at,
                $at->copy()->addMonthsNoOverflow((int) config('loyalty.expiry_months')), $order->id);
        });
    }

    /**
     * Use `$points` on `$order` (call inside the checkout transaction). Oldest points first.
     *
     * @throws PointsException when the customer doesn't have them (the transaction then rolls back)
     */
    public function redeem(User $user, Order $order, int $points): PointTransaction
    {
        if ($points <= 0 || $points % $this->blockPoints() !== 0 || $points < $this->minPoints()) {
            throw new PointsException("Use points in steps of {$this->blockPoints()}, from {$this->minPoints()} points.");
        }

        return DB::transaction(function () use ($user, $order, $points) {
            $this->lock($user->id);
            $this->expireDueLocked($user->id, now());
            $available = $this->ledgerBalance($user->id);
            if ($points > $available) {
                throw new PointsException('You only have '.number_format($available).' points.');
            }

            return $this->take($user->id, Type::Redeem, $points, "Used on order {$order->order_number}", now(), $order->id);
        });
    }

    /**
     * Undo points for an order after a change: cancelled / failed -> give back the points used on it;
     * refunded (fully or partly) -> take back the earned points in proportion to the refunded share.
     * Safe to call repeatedly: it only does what hasn't been done yet.
     */
    public function reverseFor(Order $order, ?CarbonInterface $at = null): void
    {
        $at = Carbon::instance($at ?? now());

        DB::transaction(function () use ($order, $at) {
            $this->lock($order->user_id);

            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
                $this->returnRedeemed($order, $at);
            }

            $earned = $this->earnedFor($order);
            $total = PlaceOrder::toSen($order->total);
            if ($earned <= 0 || ($order->status !== OrderStatus::Refunded && PlaceOrder::toSen($order->refunded_amount) <= 0)) {
                return;
            }
            $target = $order->status === OrderStatus::Refunded || $total <= 0
                ? $earned
                : min($earned, intdiv($earned * PlaceOrder::toSen($order->refunded_amount), $total));
            $due = $target - $this->reversedFor($order);
            if ($due <= 0) {
                return;
            }
            $this->expireDueLocked($order->user_id, $at);
            $taken = min($due, $this->ledgerBalance($order->user_id));
            if ($taken <= 0) {
                return;
            }
            $lot = PointTransaction::query()->where('order_id', $order->id)->where('type', Type::Earn)->value('id');
            $note = $taken < $due ? ' ('.($due - $taken).' already used, not taken back)' : '';
            $this->take($order->user_id, Type::ReverseEarn, $taken, "Refund on order {$order->order_number}{$note}", $at, $order->id, preferLot: $lot);
        });
    }

    /** Expire every lot whose expiry has passed (all customers). Returns the number of points expired. */
    public function expireDue(?CarbonInterface $at = null): int
    {
        $at = Carbon::instance($at ?? now());
        $users = PointTransaction::query()->openLots()->where('expires_at', '<=', $at)->distinct()->pluck('user_id');
        $expired = 0;
        foreach ($users as $userId) {
            $expired += DB::transaction(function () use ($userId, $at) {
                $this->lock($userId);

                return $this->expireDueLocked($userId, $at);
            });
        }

        return $expired;
    }

    /** Expire one customer's due points (e.g. lazily when they open My points). */
    public function expireDueFor(User|int $user, ?CarbonInterface $at = null): int
    {
        $at = Carbon::instance($at ?? now());

        return DB::transaction(function () use ($user, $at) {
            $this->lock($this->id($user));

            return $this->expireDueLocked($this->id($user), $at);
        });
    }

    /**
     * Admin correction: positive adds a lot (expires expiry_months from now), negative removes points (FIFO).
     *
     * @throws InvalidArgumentException without a reason or with 0 points
     * @throws PointsException when removing more than the customer has
     */
    public function adjust(User $user, int $points, string $reason, User $admin): PointTransaction
    {
        $reason = trim($reason);
        if ($reason === '' || $points === 0) {
            throw new InvalidArgumentException('A points adjustment needs a reason and a non-zero amount.');
        }

        return DB::transaction(function () use ($user, $points, $reason, $admin) {
            $this->lock($user->id);
            $this->expireDueLocked($user->id, now());
            $description = "Adjustment by {$admin->name}: {$reason}";
            if ($points > 0) {
                return $this->addLot($user->id, Type::Adjust, $points, $description, now(),
                    now()->addMonthsNoOverflow((int) config('loyalty.expiry_months')), adminId: $admin->id);
            }
            $available = $this->ledgerBalance($user->id);
            if (-$points > $available) {
                throw new PointsException("The customer only has {$available} points.");
            }

            return $this->take($user->id, Type::Adjust, -$points, $description, now(), adminId: $admin->id);
        });
    }

    // --- internals (call with the user row locked) ---------------------------------------------------

    private function id(User|int $user): int
    {
        return $user instanceof User ? $user->id : $user;
    }

    private function lock(int $userId): void
    {
        User::query()->whereKey($userId)->lockForUpdate()->first(['id']);
    }

    private function ledgerBalance(int $userId): int
    {
        return (int) (PointTransaction::query()->where('user_id', $userId)->orderByDesc('id')->value('balance_after') ?? 0);
    }

    private function addLot(int $userId, Type $type, int $points, string $description, Carbon $at, Carbon $expiresAt, ?int $orderId = null, ?int $adminId = null): PointTransaction
    {
        return PointTransaction::create([
            'user_id' => $userId, 'order_id' => $orderId, 'type' => $type, 'points' => $points,
            'balance_after' => $this->ledgerBalance($userId) + $points, 'description' => $description,
            'expires_at' => $expiresAt, 'remaining' => $points, 'admin_id' => $adminId, 'created_at' => $at,
        ]);
    }

    /** Remove `$points` from the oldest-expiring lots (a preferred lot first), recording which lots gave how many. */
    private function take(int $userId, Type $type, int $points, string $description, Carbon $at, ?int $orderId = null, ?int $adminId = null, ?int $preferLot = null): PointTransaction
    {
        $lots = PointTransaction::query()->where('user_id', $userId)->openLots()->where('expires_at', '>', $at)
            ->orderByRaw('case when id = ? then 0 else 1 end', [$preferLot ?? 0])->orderBy('expires_at')->orderBy('id')
            ->lockForUpdate()->get();
        $left = $points;
        $allocations = [];
        foreach ($lots as $lot) {
            if ($left === 0) {
                break;
            }
            $use = min($left, $lot->remaining);
            $lot->update(['remaining' => $lot->remaining - $use]);
            $allocations[] = ['lot_id' => $lot->id, 'points' => $use];
            $left -= $use;
        }
        if ($left > 0) {
            throw new PointsException('Not enough points.'); // can't happen when callers check the balance first
        }

        return PointTransaction::create([
            'user_id' => $userId, 'order_id' => $orderId, 'type' => $type, 'points' => -$points,
            'balance_after' => $this->ledgerBalance($userId) - $points, 'description' => $description,
            'allocations' => $allocations, 'admin_id' => $adminId, 'created_at' => $at,
        ]);
    }

    /** Give back the points used on a cancelled / failed order, into the lots they came from (once). */
    private function returnRedeemed(Order $order, Carbon $at): void
    {
        $redeem = PointTransaction::query()->where('order_id', $order->id)->where('type', Type::Redeem)->first();
        if (! $redeem || PointTransaction::query()->where('order_id', $order->id)->where('type', Type::ReturnRedeem)->exists()) {
            return;
        }
        foreach ($redeem->allocations ?? [] as $part) {
            PointTransaction::query()->whereKey($part['lot_id'])->increment('remaining', $part['points']);
        }
        $points = -$redeem->points;
        PointTransaction::create([
            'user_id' => $order->user_id, 'order_id' => $order->id, 'type' => Type::ReturnRedeem, 'points' => $points,
            'balance_after' => $this->ledgerBalance($order->user_id) + $points,
            'description' => "Order {$order->order_number} ".strtolower($order->status->label()).': points returned',
            'allocations' => $redeem->allocations, 'created_at' => $at,
        ]);
        // Points returned to lots that have expired meanwhile expire straight away.
        $this->expireDueLocked($order->user_id, $at);
    }

    /** Expire this customer's lots due by `$at`, oldest first; each expiry row is dated at the lot's expiry. */
    private function expireDueLocked(int $userId, Carbon $at): int
    {
        $lots = PointTransaction::query()->where('user_id', $userId)->openLots()->where('expires_at', '<=', $at)
            ->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
        $total = 0;
        foreach ($lots as $lot) {
            $points = $lot->remaining;
            $lot->update(['remaining' => 0]);
            PointTransaction::create([
                'user_id' => $userId, 'type' => Type::Expire, 'points' => -$points,
                'balance_after' => $this->ledgerBalance($userId) - $points,
                'description' => 'Points from '.$lot->created_at->shopDate().' expired',
                'allocations' => [['lot_id' => $lot->id, 'points' => $points]], 'created_at' => $lot->expires_at->lessThan($at) ? $lot->expires_at : $at,
            ]);
            $total += $points;
        }

        return $total;
    }
}
