<x-layouts.account :title="'Review '.$item->product->name">
    <a href="{{ route('account.orders.show', $order->order_number) }}" class="text-sm text-stone-600 hover:underline">&larr; Order {{ $order->order_number }}</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight">Write a review</h1>

    <div class="mt-4 flex items-center gap-4 rounded-xl border border-stone-200 bg-white p-4">
        @if ($url = $item->product->primaryImage?->url)
            <img src="{{ $url }}" alt="" class="size-16 rounded-lg object-cover">
        @else
            <x-shoe-placeholder class="size-16 rounded-lg" />
        @endif
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $item->product->brand }}</p>
            <p class="font-semibold">{{ $item->product->name }}</p>
            <p class="text-sm text-stone-600">{{ $item->product->size_label }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('account.reviews.store', [$order->order_number, $item->id]) }}"
          class="mt-6 space-y-5 rounded-xl border border-stone-200 bg-white p-5 sm:p-6"
          onsubmit="this.querySelector('[type=submit]').disabled = true">
        @csrf

        {{-- Star picker: radios in reverse order so "this star and the ones before it" light up with CSS
             (flex-row-reverse + peer). Works with arrow keys and screen readers. --}}
        <fieldset>
            <legend class="text-sm font-medium text-stone-700">Your rating</legend>
            <div class="mt-2 inline-flex flex-row-reverse justify-end gap-1">
                @foreach (range(5, 1) as $stars)
                    <input type="radio" id="rating-{{ $stars }}" name="rating" value="{{ $stars }}" class="peer sr-only" @checked((int) old('rating') === $stars) required>
                    <label for="rating-{{ $stars }}" title="{{ $stars }} {{ str('star')->plural($stars) }}"
                           class="cursor-pointer text-stone-300 hover:text-amber-500 peer-checked:text-amber-500 peer-hover:text-amber-500 peer-focus-visible:outline-2 peer-focus-visible:outline-emerald-600 [&:hover~label]:text-amber-500">
                        <span class="sr-only">{{ $stars }} {{ str('star')->plural($stars) }}</span>
                        <svg viewBox="0 0 20 20" class="size-9" fill="currentColor" aria-hidden="true"><path d="M10 1.6l2.6 5.3 5.8.8-4.2 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.6 7.7l5.8-.8z"/></svg>
                    </label>
                @endforeach
            </div>
            @error('rating')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </fieldset>

        <div>
            <label for="comment" class="mb-1 block text-sm font-medium text-stone-700">Your review</label>
            <textarea id="comment" name="comment" rows="5" required minlength="{{ \App\Models\Review::MIN_COMMENT }}" maxlength="{{ \App\Models\Review::MAX_COMMENT }}"
                      placeholder="How was the fit and the condition compared with the listing?"
                      @error('comment') aria-invalid="true" aria-describedby="comment-error" @enderror
                      @class([
                          'block w-full rounded-md border bg-white px-3 py-2.5 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
                          'border-red-400' => $errors->has('comment'),
                          'border-stone-300' => ! $errors->has('comment'),
                      ])>{{ old('comment') }}</textarea>
            @error('comment')
                <p id="comment-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-xs text-stone-500">Shown on the product page with your first name and last initial. Reviews can't be edited after posting.</p>
        </div>

        <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700 disabled:opacity-60">Post review</button>
    </form>
</x-layouts.account>
