<?php

namespace App\Http\Controllers;

use App\Enums\ContactTopic;
use App\Models\ContactMessage;
use App\Services\AdminNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer Service page: contact details plus a form whose messages admins handle in /admin/messages.
 */
class ContactController extends Controller
{
    /**
     * Hidden field real visitors never fill in; bots that fill every field are quietly ignored.
     */
    public const HONEYPOT = 'website';

    public function create(Request $request): View
    {
        return view('pages.customer-service', [
            'topics' => ContactTopic::cases(),
            'user' => $request->user(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $thanks = 'Thanks, we got your message. We reply within '.config('shop.support.reply_within').'.';

        if (filled($request->input(self::HONEYPOT))) {
            return redirect()->route('pages.customer-service')->with('success', $thanks);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'topic' => ['required', Rule::enum(ContactTopic::class)],
            'order_number' => ['nullable', 'string', 'max:30', 'regex:/^TS-\d{8}-[A-Z0-9]{6}$/i'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'order_number.regex' => 'Order numbers look like TS-20261026-K7M2QX.',
            'topic.required' => 'Choose what your message is about.',
        ]);

        $message = ContactMessage::create([
            ...$data,
            'order_number' => isset($data['order_number']) ? strtoupper(trim($data['order_number'])) : null,
            'user_id' => $request->user()?->id,
        ]);
        AdminNotifier::newMessage($message);

        return redirect()->route('pages.customer-service')->with('success', $thanks);
    }
}
