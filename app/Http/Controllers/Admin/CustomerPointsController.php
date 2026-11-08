<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Loyalty\PointsException;
use App\Services\Loyalty\PointsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A customer's loyalty points (admin): balance, full history and manual adjustments (reason required;
 * the admin's id is stored on the ledger row and named in its description).
 */
class CustomerPointsController extends Controller
{
    private const PER_PAGE = 30;

    public function index(User $user, PointsService $points): View
    {
        abort_unless($user->role === UserRole::Customer, 404);
        $balance = $points->balance($user);

        return view('admin.customers.points', [
            'customer' => $user,
            'balance' => $balance,
            'value' => $points->discountFor($balance), // usable value: whole blocks only
            'expiringSoon' => $points->expiringSoon($user),
            'history' => $user->pointTransactions()->with(['order:id,order_number', 'admin:id,name'])->orderByDesc('id')->paginate(self::PER_PAGE),
        ]);
    }

    public function adjust(Request $request, User $user, PointsService $points): RedirectResponse
    {
        abort_unless($user->role === UserRole::Customer, 404);
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ], [
            'points.not_in' => 'Enter a positive number to add points or a negative number to remove them.',
            'reason.required' => 'Give a reason for the adjustment (it is kept in the history).',
        ]);

        try {
            $points->adjust($user, (int) $data['points'], $data['reason'], $request->user());
        } catch (PointsException $e) {
            return back()->withInput()->withErrors(['points' => $e->getMessage()]);
        }

        $verb = $data['points'] > 0 ? 'Added' : 'Removed';

        return back()->with('success', "{$verb} ".number_format(abs($data['points']))." points for {$user->name}.");
    }
}
