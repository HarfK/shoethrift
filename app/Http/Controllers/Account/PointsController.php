<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Loyalty\PointsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My points": the signed-in customer's loyalty balance, points expiring soon and history.
 * Only ever the customer's own ledger ($request->user()), so there is no id in the URL to guess.
 */
class PointsController extends Controller
{
    private const PER_PAGE = 20;

    public function __invoke(Request $request, PointsService $points): View
    {
        $user = $request->user();
        $points->expireDueFor($user); // lazily apply expiry the 00:30 job hasn't run yet (no-op if nothing is due)
        $balance = $points->balance($user);

        return view('account.points', [
            'balance' => $balance,
            'value' => $points->discountFor($balance), // usable value: whole blocks only
            'expiringSoon' => $points->expiringSoon($user),
            'nextExpiry' => $points->nextExpiry($user),
            'history' => $user->pointTransactions()->with('order:id,order_number')->orderByDesc('id')->paginate(self::PER_PAGE),
        ]);
    }
}
