<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin bell (App\Notifications\AdminAlert). Each admin has their own notifications and
 * read state; another admin's notification id is a 404.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $unreadOnly = $request->query('filter') === 'unread';

        return view('admin.notifications.index', [
            'notifications' => ($unreadOnly ? $request->user()->unreadNotifications() : $request->user()->notifications())
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'unreadOnly' => $unreadOnly,
        ]);
    }

    /**
     * Mark one notification read and go to the item it's about.
     */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return redirect()->to($notification->data['url'] ?? route('admin.dashboard'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
