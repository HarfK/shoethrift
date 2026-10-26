<x-layouts.account title="My addresses">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">My addresses</h1>
            <p class="mt-1 text-sm text-stone-600">Choose one at checkout. Your default is selected first. Editing an address doesn't change past orders.</p>
        </div>
        @if ($canAddMore)
            <a href="{{ route('account.addresses.create') }}" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Add address</a>
        @endif
    </div>

    <ul class="mt-6 grid gap-4 sm:grid-cols-2">
        @forelse ($addresses as $address)
            <li @class(['rounded-xl border bg-white p-5 text-sm', 'border-emerald-600 ring-1 ring-emerald-600' => $address->is_default, 'border-stone-200' => ! $address->is_default]) data-address="{{ $address->id }}">
                <p class="flex flex-wrap items-center gap-2 font-semibold">
                    {{ $address->recipient_name }}
                    @if ($address->label)<span class="rounded bg-stone-100 px-1.5 text-xs font-medium text-stone-600">{{ $address->label }}</span>@endif
                    @if ($address->is_default)<span class="rounded bg-emerald-50 px-1.5 text-xs font-medium text-emerald-800">Default</span>@endif
                </p>
                <p class="mt-1 text-stone-600">{{ $address->phone }}</p>
                <p class="mt-2 whitespace-pre-line text-stone-700">{{ implode("\n", $address->lines()) }}</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('account.addresses.edit', $address->id) }}" class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-50">Edit</a>
                    @unless ($address->is_default)
                        <form method="POST" action="{{ route('account.addresses.default', $address->id) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-50">Set as default</button>
                        </form>
                    @endunless
                    <form method="POST" action="{{ route('account.addresses.destroy', $address->id) }}" onsubmit="return confirm('Delete this address?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-md border border-red-300 px-3 py-1.5 font-medium text-red-700 hover:bg-red-50">Delete</button>
                    </form>
                </div>
            </li>
        @empty
            <li class="rounded-xl border border-dashed border-stone-300 p-8 text-center text-sm text-stone-600 sm:col-span-2">
                You haven't saved any addresses yet.
                <a href="{{ route('account.addresses.create') }}" class="font-medium text-emerald-700 underline">Add your first address</a>
            </li>
        @endforelse
    </ul>

    @unless ($canAddMore)
        <p class="mt-4 text-sm text-stone-600">You've saved the maximum of {{ config('shop.max_addresses') }} addresses. Delete one to add another.</p>
    @endunless
</x-layouts.account>
