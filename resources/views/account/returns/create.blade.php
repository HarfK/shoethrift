<x-layouts.shop :title="'Return items from '.$order->order_number">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <a href="{{ route('account.orders.show', $order->order_number) }}" class="text-sm text-stone-600 hover:underline">&larr; Order {{ $order->order_number }}</a>
        <h1 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Request a return</h1>
        <p class="mt-1 text-sm text-stone-600">
            You can request a return until <strong>{{ $order->returnDeadline()->shopDateTime() }}</strong>.
            Read the <a href="{{ route('pages.returns') }}" class="text-emerald-700 underline">Return & Refund Policy</a> first.
        </p>

        <form method="POST" action="{{ route('account.returns.store', $order->order_number) }}" enctype="multipart/form-data"
              class="mt-6 space-y-6 rounded-xl border border-stone-200 bg-white p-5 sm:p-6"
              onsubmit="this.querySelector('[type=submit]').disabled = true">
            @csrf

            <fieldset>
                <legend class="font-semibold">Which pairs are you returning?</legend>
                <div class="mt-3 space-y-2">
                    @foreach ($order->items as $item)
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-stone-200 p-3 has-checked:border-stone-900">
                            <input type="checkbox" name="items[]" value="{{ $item->id }}" class="rounded border-stone-300 text-stone-900"
                                   @checked(in_array($item->id, array_map('intval', old('items', $order->items->count() === 1 ? [$item->id] : []))))>
                            <span class="flex-1 text-sm">
                                <span class="block font-medium">{{ $item->product->name }}</span>
                                <span class="text-stone-600">{{ $item->product->size_label }} · Qty {{ $item->quantity }}</span>
                            </span>
                            <span class="text-sm tabular-nums">RM {{ number_format($item->price * $item->quantity, 2) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('items')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </fieldset>

            <x-form.select name="reason" label="Reason" placeholder="Choose a reason" required
                           :options="collect($reasons)->mapWithKeys(fn ($reason) => [$reason->value => $reason->label()])" />

            <div>
                <label for="details" class="mb-1 block text-sm font-medium text-stone-700">Tell us more</label>
                <textarea id="details" name="details" rows="4" required minlength="10" maxlength="2000"
                          placeholder="For example: the left toe box has a scuff that wasn't in the photos."
                          @error('details') aria-invalid="true" aria-describedby="details-error" @enderror
                          @class([
                              'block w-full rounded-md border bg-white px-3 py-2.5 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
                              'border-red-400' => $errors->has('details'),
                              'border-stone-300' => ! $errors->has('details'),
                          ])>{{ old('details') }}</textarea>
                @error('details')
                    <p id="details-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="photos" class="mb-1 block text-sm font-medium text-stone-700">Photos (optional, up to {{ config('shop.returns.max_photos') }})</label>
                <input id="photos" name="photos[]" type="file" multiple accept="image/jpeg,image/png,image/webp"
                       class="block w-full text-sm text-stone-700 file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-medium hover:file:bg-stone-200">
                <p class="mt-1 text-xs text-stone-500">JPG, PNG or WebP, up to 4 MB each. Only you and our staff can see them.</p>
                @foreach ($errors->get('photos*') as $messages)
                    @foreach ($messages as $message)
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @endforeach
                @endforeach
            </div>

            <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 font-semibold text-white hover:bg-stone-700 disabled:opacity-60">Send return request</button>
        </form>
    </div>
</x-layouts.shop>
