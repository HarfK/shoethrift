{{-- Profile photo in a circle, or the user's initials when there is none. Size via class (e.g. size-10). --}}
@props(['user'])

@if ($url = $user->avatarUrl())
    <img src="{{ $url }}" alt="" {{ $attributes->class(['shrink-0 rounded-full object-cover']) }}>
@else
    <span aria-hidden="true" {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-full bg-emerald-100 font-semibold text-emerald-800']) }}>{{ $user->initials() }}</span>
@endif
