<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RefundMethod;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\ReturnRequest;
use App\Services\Returns\RecordRefund;
use App\Services\Returns\RefundFailedException;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin side of returns: review, approve or reject, then record the refund (RecordRefund).
 */
class ReturnRequestController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $status = ReturnStatus::tryFrom($request->string('status')->toString());

        $returns = ReturnRequest::query()
            ->with(['order', 'user'])
            ->withCount('items')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.returns.index', [
            'returns' => $returns,
            'statuses' => ReturnStatus::cases(),
            'status' => $status,
        ]);
    }

    public function show(ReturnRequest $return): View
    {
        $return->load(['order.user', 'items.orderItem.product.primaryImage', 'photos']);

        return view('admin.returns.show', [
            'return' => $return,
            'canUseHitPay' => RecordRefund::canUseHitPay($return->order),
        ]);
    }

    public function decide(Request $request, ReturnRequest $return): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'admin_note' => [Rule::requiredIf($request->input('decision') === 'reject'), 'nullable', 'string', 'max:1000'],
        ], [
            'admin_note.required' => 'Tell the customer why the return was rejected.',
        ]);

        try {
            $data['decision'] === 'approve'
                ? $return->approve($data['admin_note'] ?? null)
                : $return->reject($data['admin_note']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.returns.show', $return)->with('success', $data['decision'] === 'approve'
            ? 'Return approved. The customer can now send the pairs back.'
            : 'Return rejected. The customer can see your note.');
    }

    public function refund(Request $request, ReturnRequest $return, RecordRefund $recordRefund): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'method' => ['required', Rule::enum(RefundMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'restock' => ['nullable', 'array'],
            'restock.*' => ['integer'],
            'retry_confirmed' => ['nullable', 'boolean'],
        ]);

        try {
            $recordRefund->handle(
                $return,
                (string) $data['amount'],
                RefundMethod::from($data['method']),
                $data['reference'] ?? null,
                array_map('intval', $data['restock'] ?? []),
                $request->boolean('retry_confirmed'),
            );
        } catch (RefundFailedException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $return->refresh();

        return redirect()->route('admin.returns.show', $return)
            ->with('success', "Refund of RM {$return->refund_amount} recorded ({$return->refund_method->label()}, ref {$return->refund_reference}).");
    }
}
