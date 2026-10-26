{{-- Admin notification bell: unread count + latest items. <details> dropdown, no JS. --}}
@php
    $user = auth()->user();
    $unread = $user->unreadNotifications()->count();
    $latest = $user->notifications()->limit(8)->get();
    $icons = ['order' => '🛒', 'return' => '↩️', 'low_stock' => '📦', 'payment_failed' => '⚠️', 'message' => '✉️', 'review' => '⭐'];
@endphp

<details class="relative" data-notification-bell>
    <summary class="relative flex cursor-pointer list-none items-center rounded-full p-2 text-stone-700 hover:bg-stone-200"
             aria-label="Notifications, {{ $unread }} unread">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
        </svg>
        @if ($unread > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-5 rounded-full bg-red-600 px-1 text-center text-xs font-semibold leading-5 text-white" data-unread-count>{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </summary>

    <div class="absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg sm:w-96">
        <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3">
            <p class="text-sm font-semibold">Notifications</p>
            @if ($unread > 0)
                <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-emerald-700 hover:underline">Mark all as read</button>
                </form>
            @endif
        </div>

        <ul class="max-h-96 divide-y divide-stone-100 overflow-y-auto">
            @forelse ($latest as $notification)
                <li>
                    <form method="POST" action="{{ route('admin.notifications.open', $notification->id) }}">
                        @csrf
                        <button type="submit" @class(['flex w-full gap-3 px-4 py-3 text-left text-sm hover:bg-stone-50', 'bg-emerald-50/60' => $notification->read_at === null])>
                            <span aria-hidden="true">{{ $icons[$notification->data['kind'] ?? ''] ?? '•' }}</span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate', 'font-semibold' => $notification->read_at === null])>{{ $notification->data['title'] ?? 'Notification' }}</span>
                                @if (! empty($notification->data['body']))
                                    <span class="block truncate text-stone-600">{{ $notification->data['body'] }}</span>
                                @endif
                                <span class="block text-xs text-stone-500">{{ $notification->created_at->shopDateTime() }}</span>
                            </span>
                            @if ($notification->read_at === null)
                                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-emerald-600" aria-label="Unread"></span>
                            @endif
                        </button>
                    </form>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-stone-500">No notifications yet.</li>
            @endforelse
        </ul>

        <a href="{{ route('admin.notifications.index') }}" class="block border-t border-stone-200 px-4 py-2.5 text-center text-sm font-medium text-emerald-700 hover:bg-stone-50">See all notifications</a>
    </div>
</details>
