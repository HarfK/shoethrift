<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's notifications (App\Notifications\CustomerAlert). Always looked up through
 * $request->user(), so another user's notification id is a 404.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        return view('account.notifications.index', [
            'notifications' => $request->user()->customerNotifications()->paginate(self::PER_PAGE),
            'unread' => $request->user()->customerNotifications()->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Mark one notification read and go to the order or product it's about.
     */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->customerNotifications()->findOrFail($id);
        $notification->markAsRead();

        // Only same-site relative paths (we store them that way); anything else goes to the list.
        $url = $notification->data['url'] ?? '';
        $safe = is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '\\');

        return redirect()->to($safe ? $url : route('account.notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->customerNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
