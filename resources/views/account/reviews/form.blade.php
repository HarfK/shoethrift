{{-- Write ($review not saved yet) or edit ($review->exists) a review, with photos and a video. --}}
@use('App\Models\ReviewMedia')

<x-layouts.account :title="($review->exists ? 'Edit review: ' : 'Review ').$item->product->name">
    <a href="{{ route('account.orders.show', $order->order_number) }}" class="text-sm text-stone-600 hover:underline">&larr; Order {{ $order->order_number }}</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $review->exists ? 'Edit your review' : 'Write a review' }}</h1>

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

    @if ($review->exists && $review->isHidden())
        <p class="mt-4 rounded-md border border-stone-300 bg-stone-100 px-4 py-3 text-sm text-stone-700">This review is hidden by ShoeThrift. Changes are saved, but it stays hidden until it's checked again.</p>
    @endif

    <form method="POST" enctype="multipart/form-data"
          action="{{ $review->exists ? route('account.reviews.update', $review) : route('account.reviews.store', [$order->order_number, $item->id]) }}"
          class="mt-6 space-y-6 rounded-xl border border-stone-200 bg-white p-5 sm:p-6"
          onsubmit="this.querySelector('[type=submit]').disabled = true">
        @csrf
        @if ($review->exists)
            @method('PUT')
        @endif

        <div>
            <x-review-star-picker :value="old('rating', $review->rating)" />
            @error('rating')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- 1 to 5 in natural order (whole numbers). --}}
        <fieldset>
            <legend class="text-sm font-medium text-stone-700">Condition matched the description?</legend>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach (\App\Models\Review::CONDITION_LABELS as $value => $label)
                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-300 px-3 py-2 text-sm has-checked:border-stone-900 has-checked:bg-stone-900 has-checked:text-white has-focus-visible:ring-2 has-focus-visible:ring-emerald-600">
                        <input type="radio" name="condition_rating" value="{{ $value }}" class="sr-only" required
                               @checked((int) old('condition_rating', $review->condition_rating) === $value)>
                        <span class="font-semibold tabular-nums">{{ $value }}</span>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('condition_rating')
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
                      ])>{{ old('comment', $review->comment) }}</textarea>
            @error('comment')
                <p id="comment-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Existing media (edit): tick to remove. --}}
        @if ($review->exists && $review->media->isNotEmpty())
            <fieldset>
                <legend class="text-sm font-medium text-stone-700">Your photos and video</legend>
                <ul class="mt-2 flex flex-wrap gap-3">
                    @foreach ($review->media as $media)
                        <li class="w-28 text-xs">
                            @if ($media->isHidden())
                                <div class="flex aspect-square items-center justify-center rounded-md bg-stone-100 p-2 text-center text-stone-500">Hidden by ShoeThrift</div>
                            @elseif ($media->isVideo())
                                <video src="{{ $media->url() }}" class="aspect-square w-full rounded-md bg-black object-cover" preload="metadata" muted></video>
                            @else
                                <img src="{{ $media->url() }}" alt="" class="aspect-square w-full rounded-md object-cover">
                            @endif
                            <label class="mt-1 flex items-center gap-1 text-red-700">
                                <input type="checkbox" name="remove_media[]" value="{{ $media->id }}" class="rounded border-stone-300"
                                       @checked(in_array($media->id, array_map('intval', (array) old('remove_media', [])), true))>
                                Remove
                            </label>
                        </li>
                    @endforeach
                </ul>
            </fieldset>
        @endif

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <label for="photos" class="mb-1 block text-sm font-medium text-stone-700">Add photos (up to {{ ReviewMedia::MAX_PHOTOS }} in total)</label>
                <input id="photos" name="photos[]" type="file" multiple accept="image/jpeg,image/png,image/webp"
                       class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-medium hover:file:bg-stone-200">
                <p class="mt-1 text-xs text-stone-500">JPG, PNG or WebP, up to 5 MB each.</p>
                @foreach ($errors->get('photos*') as $messages)
                    @foreach ($messages as $message)
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @endforeach
                @endforeach
            </div>
            <div>
                <label for="video" class="mb-1 block text-sm font-medium text-stone-700">Add a short video (optional)</label>
                <input id="video" name="video" type="file" accept="video/mp4,video/webm"
                       class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-medium hover:file:bg-stone-200">
                <p class="mt-1 text-xs text-stone-500">MP4 or WebM, up to 30 MB and {{ ReviewMedia::MAX_VIDEO_SECONDS }} seconds.</p>
                <p id="video-too-long" class="mt-1 hidden text-sm text-amber-700" role="alert"></p>
                @error('video')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <p class="text-xs text-stone-500">Shown on the product page and the store's Reviews page with your first name and last initial.{{ $review->exists ? ' Edited reviews are marked "Edited".' : '' }}</p>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700 disabled:opacity-60">{{ $review->exists ? 'Save changes' : 'Post review' }}</button>
            <a href="{{ route('account.orders.show', $order->order_number) }}" class="text-sm text-stone-600 hover:underline">Cancel</a>
        </div>
    </form>

    @if ($review->exists)
        <form method="POST" action="{{ route('account.reviews.destroy', $review) }}" class="mt-4" onsubmit="return confirm('Delete your review and its photos and video?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm font-medium text-red-700 hover:underline">Delete this review</button>
        </form>
    @endif

    {{-- Friendly early warning only; the server checks the length again. --}}
    <script>
        document.getElementById('video').addEventListener('change', (event) => {
            const file = event.target.files[0];
            const warning = document.getElementById('video-too-long');
            warning.classList.add('hidden');
            if (!file) return;
            const probe = document.createElement('video');
            probe.preload = 'metadata';
            probe.onloadedmetadata = () => {
                URL.revokeObjectURL(probe.src);
                if (probe.duration > {{ ReviewMedia::MAX_VIDEO_SECONDS }}) {
                    warning.textContent = 'This video is ' + Math.round(probe.duration) + ' seconds long. Please trim it to {{ ReviewMedia::MAX_VIDEO_SECONDS }} seconds or less.';
                    warning.classList.remove('hidden');
                }
            };
            probe.src = URL.createObjectURL(file);
        });
    </script>
</x-layouts.account>
