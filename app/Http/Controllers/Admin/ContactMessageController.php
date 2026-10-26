<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Messages from the Customer Service form. Opening one marks it read; admins reply by email
 * and then mark it resolved.
 */
class ContactMessageController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $filter = in_array($request->query('filter'), ['open', 'resolved', 'all'], true) ? $request->query('filter') : 'open';

        $messages = ContactMessage::query()
            ->when($filter === 'open', fn ($query) => $query->open())
            ->when($filter === 'resolved', fn ($query) => $query->whereNotNull('resolved_at'))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.messages.index', ['messages' => $messages, 'filter' => $filter]);
    }

    public function show(ContactMessage $message): View
    {
        if ($message->read_at === null) {
            $message->forceFill(['read_at' => now()])->save();
        }

        return view('admin.messages.show', ['message' => $message->load('user')]);
    }

    public function resolve(ContactMessage $message): RedirectResponse
    {
        $message->forceFill(['resolved_at' => $message->resolved_at ?? now()])->save();

        return redirect()->route('admin.messages.index')->with('success', "Message from {$message->name} marked as resolved.");
    }
}
