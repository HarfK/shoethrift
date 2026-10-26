<x-layouts.account :title="$address->exists ? 'Edit address' : 'Add address'">
    <a href="{{ route('account.addresses.index') }}" class="text-sm text-stone-600 hover:underline">&larr; My addresses</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $address->exists ? 'Edit address' : 'Add address' }}</h1>

    <form method="POST" action="{{ $address->exists ? route('account.addresses.update', $address->id) : route('account.addresses.store') }}"
          class="mt-6 rounded-xl border border-stone-200 bg-white p-5 sm:p-6">
        @csrf
        @if ($address->exists)
            @method('PUT')
        @endif

        @include('partials.address-fields', ['address' => $address, 'required' => true, 'withLabel' => true])

        @unless ($address->is_default)
            <label class="mb-4 flex items-center gap-2 text-sm">
                <input type="hidden" name="make_default" value="0">
                <input type="checkbox" name="make_default" value="1" class="rounded border-stone-300" @checked(old('make_default'))>
                Make this my default address
            </label>
        @endunless

        <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">{{ $address->exists ? 'Save address' : 'Add address' }}</button>
    </form>
</x-layouts.account>
