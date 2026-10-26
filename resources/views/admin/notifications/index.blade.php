<x-layouts.admin title="Notifications">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <h1 class="text-2xl font-semibold">Notifications</h1>
        <form method="POST" action="{{ route('admin.notifications.read-all') }}">
            @csrf
            <button type="submit" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Mark all as read</button>
        </form>
    </div>

    <nav class="mt-6 flex gap-2 text-sm" aria-label="Filter notifications">
        <a href="{{ route('admin.notifications.index') }}" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-stone-800 text-white' => ! $unreadOnly, 'border border-stone-300 bg-white hover:bg-stone-50' => $unreadOnly])>All</a>
        <a href="{{ route('admin.notifications.index', ['filter' => 'unread']) }}" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-stone-800 text-white' => $unreadOnly, 'border border-stone-300 bg-white hover:bg-stone-50' => ! $unreadOnly])>Unread</a>
    </nav>

    <ul class="mt-4 divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
        @forelse ($notifications as $notification)
            <li>
                <form method="POST" action="{{ route('admin.notifications.open', $notification->id) }}">
                    @csrf
                    <button type="submit" @class(['flex w-full flex-wrap items-baseline justify-between gap-2 px-5 py-3 text-left text-sm hover:bg-stone-50', 'bg-emerald-50/60' => $notification->read_at === null])>
                        <span class="min-w-0">
                            <span class="mr-2 rounded bg-stone-100 px-1.5 py-0.5 text-xs text-stone-600">{{ \App\Notifications\AdminAlert::TYPES[$notification->data['kind'] ?? ''] ?? 'Notice' }}</span>
                            <span @class(['font-semibold' => $notification->read_at === null])>{{ $notification->data['title'] ?? 'Notification' }}</span>
                            @if (! empty($notification->data['body']))
                                <span class="block text-stone-600">{{ $notification->data['body'] }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-xs text-stone-500">{{ $notification->created_at->shopDateTime() }}</span>
                    </button>
                </form>
            </li>
        @empty
            <li class="px-5 py-12 text-center text-sm text-stone-500">{{ $unreadOnly ? 'No unread notifications.' : 'No notifications yet.' }}</li>
        @endforelse
    </ul>

    <div class="mt-6">{{ $notifications->links() }}</div>
</x-layouts.admin>
