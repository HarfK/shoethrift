{{--
    Layout for the footer's content pages (policies, FAQ, terms, privacy).
    The policy text is a draft written for this FYP, not legal advice; numbers come from config/shop.php.
--}}
@props(['title', 'intro' => null, 'updated' => '26 Oct 2026'])

<x-layouts.shop :title="$title">
    <article class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <nav class="text-sm text-stone-500" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" class="hover:text-stone-900">Home</a>
            <span aria-hidden="true">›</span>
            <span class="text-stone-900">{{ $title }}</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">{{ $title }}</h1>
        @if ($intro)
            <p class="mt-3 text-lg text-stone-600">{{ $intro }}</p>
        @endif
        <p class="mt-2 text-sm text-stone-500">Last updated {{ $updated }}</p>

        <div class="mt-8 space-y-4 text-stone-700 leading-relaxed
                    [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-stone-900
                    [&_h3]:mt-6 [&_h3]:font-semibold [&_h3]:text-stone-900
                    [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-6
                    [&_a]:font-medium [&_a]:text-emerald-700 [&_a]:underline">
            {{ $slot }}
        </div>

        <aside class="mt-12 rounded-xl border border-stone-200 bg-white p-5 text-sm">
            <p class="font-semibold">Still need help?</p>
            <p class="mt-1 text-stone-600">
                <a href="{{ route('pages.customer-service') }}" class="font-medium text-emerald-700 underline">Contact Customer Service</a>
                or email {{ config('shop.support.email') }}. We reply within {{ config('shop.support.reply_within') }}.
            </p>
        </aside>
    </article>
</x-layouts.shop>
