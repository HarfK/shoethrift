{{--
    A review's visible photos (thumbnails → click-to-enlarge lightbox) and video (plays in place).
    The lightbox needs no JS: each photo has a fixed overlay shown with CSS :target (#review-photo-N),
    with Previous / Next / Close links. Pass the review with `visibleMedia` loaded.
--}}
@props(['review'])

@php
    $photos = $review->visibleMedia->where('type', 'photo')->values();
    $video = $review->visibleMedia->firstWhere('type', 'video');
@endphp

@if ($photos->isNotEmpty() || $video)
    <div {{ $attributes->class('mt-3 space-y-3') }} data-review-media>
        @if ($photos->isNotEmpty())
            <ul class="flex flex-wrap gap-2">
                @foreach ($photos as $photo)
                    <li>
                        <a href="#review-photo-{{ $photo->id }}" class="block overflow-hidden rounded-md ring-1 ring-stone-200 hover:ring-stone-400">
                            <img src="{{ $photo->url() }}" alt="Customer photo {{ $loop->iteration }} of {{ $photos->count() }}" class="size-20 object-cover" loading="lazy">
                        </a>
                    </li>
                @endforeach
            </ul>

            @foreach ($photos as $photo)
                <div id="review-photo-{{ $photo->id }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/85 p-4 target:flex" role="dialog" aria-label="Customer photo {{ $loop->iteration }} of {{ $photos->count() }}">
                    <a href="#review-{{ $review->id }}" class="absolute inset-0" aria-label="Close"></a>
                    <figure class="relative max-h-full max-w-3xl">
                        <img src="{{ $photo->url() }}" alt="Customer photo {{ $loop->iteration }} of {{ $photos->count() }}" class="max-h-[80vh] w-auto rounded-lg object-contain">
                        <figcaption class="mt-3 flex items-center justify-between gap-4 text-sm text-white">
                            <span>{{ $loop->iteration }} / {{ $photos->count() }}</span>
                            <span class="flex gap-4">
                                @unless ($loop->first)
                                    <a href="#review-photo-{{ $photos[$loop->index - 1]->id }}" class="underline">Previous</a>
                                @endunless
                                @unless ($loop->last)
                                    <a href="#review-photo-{{ $photos[$loop->index + 1]->id }}" class="underline">Next</a>
                                @endunless
                                <a href="#review-{{ $review->id }}" class="font-semibold underline">Close</a>
                            </span>
                        </figcaption>
                    </figure>
                </div>
            @endforeach
        @endif

        @if ($video)
            <video src="{{ $video->url() }}" controls preload="metadata" playsinline class="max-h-72 w-full max-w-md rounded-lg bg-black" data-review-video>
                Your browser can't play this video. <a href="{{ $video->url() }}">Download it</a>.
            </video>
        @endif
    </div>
@endif
