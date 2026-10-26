<x-layouts.admin :title="'Message from '.$message->name">
    <a href="{{ route('admin.messages.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Messages</a>
    <h1 class="mt-2 text-2xl font-semibold">{{ $message->topic->label() }}</h1>
    <p class="mt-1 text-sm text-stone-500">Received {{ $message->created_at->shopDateTime() }}</p>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm lg:col-span-2">
            <h2 class="sr-only">Message</h2>
            <p class="whitespace-pre-line text-sm leading-relaxed text-stone-800">{{ $message->message }}</p>
        </section>

        <div class="space-y-6">
            <section class="rounded-xl border border-stone-200 bg-white p-5 text-sm shadow-sm">
                <h2 class="mb-3 text-base font-semibold">From</h2>
                <p class="font-medium">{{ $message->name }}</p>
                <p><a href="mailto:{{ $message->email }}" class="text-emerald-700 hover:underline">{{ $message->email }}</a></p>
                <p class="mt-1 text-stone-600">{{ $message->user ? 'Registered customer' : 'Guest' }}</p>
                @if ($message->order_number)
                    @php($order = \App\Models\Order::firstWhere('order_number', $message->order_number))
                    <p class="mt-2">Order:
                        @if ($order)
                            <a href="{{ route('admin.orders.show', $order) }}" class="text-emerald-700 hover:underline">{{ $message->order_number }}</a>
                        @else
                            {{ $message->order_number }} <span class="text-stone-500">(not found)</span>
                        @endif
                    </p>
                @endif
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <a href="mailto:{{ $message->email }}?subject={{ rawurlencode('Re: your message to '.config('app.name')) }}"
                   class="block w-full rounded-md bg-emerald-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-800">Reply by email</a>
                @if ($message->isResolved())
                    <p class="mt-3 text-sm text-stone-600">Resolved {{ $message->resolved_at->shopDateTime() }}.</p>
                @else
                    <form method="POST" action="{{ route('admin.messages.resolve', $message) }}" class="mt-2">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="w-full rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-stone-50">Mark resolved</button>
                    </form>
                @endif
            </section>
        </div>
    </div>
</x-layouts.admin>
