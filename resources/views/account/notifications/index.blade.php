<x-layouts.account title="Notifications">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Notifications</h1>
        @if ($unread > 0)
            <form method="POST" action="{{ route('account.notifications.read-all') }}">
                @csrf
                <button type="submit" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Mark all as read</button>
            </form>
        @endif
    </div>
    <p class="mt-1 text-sm text-stone-600">{{ $unread }} unread</p>

    <ul class="mt-6 divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white" data-notifications>
        @forelse ($notifications as $notification)
            <li>
                <form method="POST" action="{{ route('account.notifications.open', $notification->id) }}">
                    @csrf
                    <button type="submit" @class(['flex w-full gap-3 px-5 py-4 text-left text-sm hover:bg-stone-50', 'bg-emerald-50/70' => $notification->read_at === null])
                            @if ($notification->read_at === null) data-unread @endif>
                        <span class="min-w-0 flex-1">
                            <span @class(['block', 'font-semibold' => $notification->read_at === null])>{{ $notification->data['title'] ?? 'Notification' }}</span>
                            @if (! empty($notification->data['body']))
                                <span class="block text-stone-600">{{ $notification->data['body'] }}</span>
                            @endif
                            <span class="mt-1 block text-xs text-stone-500">{{ $notification->created_at->shopDateTime() }}</span>
                        </span>
                        @if ($notification->read_at === null)
                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-emerald-600" aria-label="Unread"></span>
                        @endif
                    </button>
                </form>
            </li>
        @empty
            <li class="px-5 py-10 text-center text-sm text-stone-500">No notifications yet.</li>
        @endforelse
    </ul>

    <div class="mt-6">{{ $notifications->links() }}</div>
</x-layouts.account>
