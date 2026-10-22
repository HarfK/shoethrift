{{-- Shown when a product has no photo (or its file is missing). --}}
<div {{ $attributes->class('flex items-center justify-center bg-stone-100 text-stone-300') }} role="img" aria-label="No photo yet">
    <svg viewBox="0 0 64 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" class="w-1/2 max-w-24" aria-hidden="true">
        <path d="M4 30h52c2.2 0 4-1.8 4-4 0-3-3-5-7-6l-14-4-8-10c-.8-1-2-2-4-2H14c-1.7 0-3 1.3-3 3v8c0 1.7-1.3 3-3 3H6c-1.7 0-3 1.3-3 3v8c0 .6.4 1 1 1z"/>
        <path d="M3 34h58M20 16l4 4M26 12l4 4"/>
    </svg>
</div>
