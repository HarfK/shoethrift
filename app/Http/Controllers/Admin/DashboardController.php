<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'Products' => Product::count(),
                'Available products' => Product::available()->count(),
                'Orders' => Order::count(),
                'Customers' => User::where('role', UserRole::Customer)->count(),
            ],
        ]);
    }
}
