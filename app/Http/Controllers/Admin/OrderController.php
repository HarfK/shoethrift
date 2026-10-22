<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Models\Order;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();
        $status = OrderStatus::tryFrom($request->string('status')->toString());
        $payment = PaymentMethod::tryFrom($request->string('payment')->toString());

        $orders = Order::query()
            ->with('user')
            ->withCount('items')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('order_number', 'like', '%'.$search.'%')
                ->orWhereHas('user', fn ($query) => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'))))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($payment, fn ($query) => $query->where('payment_method', $payment))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'statuses' => OrderStatus::cases(),
            'paymentMethods' => PaymentMethod::cases(),
            'filters' => ['search' => $search, 'status' => $status?->value, 'payment' => $payment?->value],
        ]);
    }

    public function show(Order $order): View
    {
        return view('admin.orders.show', [
            'order' => $order->load(['user', 'items.product.primaryImage']),
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
    {
        $status = $request->status();

        try {
            $order->transitionTo($status);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $status === OrderStatus::Cancelled
            ? "Order {$order->order_number} was cancelled and its items were returned to stock."
            : "Order {$order->order_number} is now {$status->label()}.";

        return redirect()->route('admin.orders.show', $order)->with('success', $message);
    }
}
