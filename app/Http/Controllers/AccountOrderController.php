<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My orders" for the logged-in customer. Only ever queries through $user->orders(),
 * so other customers' orders 404.
 */
class AccountOrderController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        return view('account.orders.index', [
            'orders' => $request->user()->orders()
                ->withCount('items')
                ->latest()
                ->latest('id')
                ->paginate(self::PER_PAGE),
        ]);
    }

    public function show(Request $request, string $orderNumber): View
    {
        return view('account.orders.show', [
            'order' => $request->user()->orders()
                ->where('order_number', $orderNumber)
                ->with('items.product.primaryImage')
                ->firstOrFail(),
        ]);
    }
}
