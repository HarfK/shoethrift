<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CartRelease;
use App\Models\User;
use App\Services\Cart\Reservations;
use Illuminate\View\View;

/**
 * Read-only view of cart holds: who holds which pair right now, and customers whose holds keep
 * running out (possible hoarding). Nothing is blocked automatically.
 */
class ReservationController extends Controller
{
    public function __invoke(): View
    {
        $windowDays = (int) config('shop.cart.expired_window_days');
        $expiredInWindow = fn ($query) => $query
            ->where('reason', CartRelease::EXPIRED)
            ->where('released_at', '>=', now()->subDays($windowDays));

        return view('admin.reservations.index', [
            'active' => Reservations::activeHolds()
                ->with(['product:id,name,slug,size,brand', 'cart.user:id,name,email'])
                ->orderBy('reserved_until')
                ->get(),
            'repeat' => User::query()
                ->select(['id', 'name', 'email'])
                ->whereHas('cartReleases', $expiredInWindow) // not having(): SQLite needs GROUP BY for it
                ->withCount(['cartReleases as expired_holds' => $expiredInWindow])
                ->orderByDesc('expired_holds')
                ->limit(50)
                ->get(),
            'windowDays' => $windowDays,
            'flagAfter' => (int) config('shop.cart.flag_expired_after'),
        ]);
    }
}
