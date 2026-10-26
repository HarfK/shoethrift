<x-layouts.admin title="Messages">
    <h1 class="text-2xl font-semibold">Messages</h1>
    <p class="mt-1 text-sm text-stone-600">From the Customer Service page. Reply by email, then mark the message resolved.</p>

    <nav class="mt-6 flex gap-2 text-sm" aria-label="Filter messages">
        @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.messages.index', ['filter' => $value]) }}"
               @if ($filter === $value) aria-current="page" @endif
               @class(['rounded-md px-3 py-1.5 font-medium', 'bg-stone-800 text-white' => $filter === $value, 'border border-stone-300 bg-white hover:bg-stone-50' => $filter !== $value])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="mt-4 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            <thead class="bg-stone-50 text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                <tr>
                    <th scope="col" class="px-4 py-3">From</th>
                    <th scope="col" class="px-4 py-3">Topic</th>
                    <th scope="col" class="px-4 py-3">Message</th>
                    <th scope="col" class="px-4 py-3">Received</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($messages as $message)
                    <tr @class(['align-top', 'font-semibold' => $message->read_at === null])>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.messages.show', $message) }}" class="text-emerald-700 hover:underline">{{ $message->name }}</a>
                            <div class="text-xs font-normal text-stone-500">{{ $message->email }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $message->topic->label() }}@if ($message->order_number)<div class="text-xs font-normal text-stone-500">{{ $message->order_number }}</div>@endif</td>
                        <td class="max-w-md px-4 py-3 font-normal text-stone-600">{{ str($message->message)->limit(90) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 font-normal text-stone-600">{{ $message->created_at->shopDateTime() }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            @if ($message->isResolved())
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20">Resolved</span>
                            @elseif ($message->read_at === null)
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">New</span>
                            @else
                                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-700 ring-1 ring-inset ring-stone-500/20">Read</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-stone-500">No messages here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $messages->links() }}</div>
</x-layouts.admin>
