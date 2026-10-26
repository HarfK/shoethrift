<x-layouts.shop title="Customer Service">
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
        <nav class="text-sm text-stone-500" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" class="hover:text-stone-900">Home</a>
            <span aria-hidden="true">›</span>
            <span class="text-stone-900">Customer Service</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">Customer Service</h1>
        <p class="mt-3 text-lg text-stone-600">Questions about an order, a pair, or a return? Send us a message and we'll reply within {{ config('shop.support.reply_within') }}.</p>

        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_18rem]">
            <form method="POST" action="{{ route('pages.customer-service.store') }}" class="rounded-xl border border-stone-200 bg-white p-5 sm:p-6">
                @csrf
                {{-- Honeypot: hidden from people; bots that fill it are ignored. --}}
                <div class="hidden" aria-hidden="true">
                    <label for="{{ \App\Http\Controllers\ContactController::HONEYPOT }}">Leave this empty</label>
                    <input type="text" id="{{ \App\Http\Controllers\ContactController::HONEYPOT }}" name="{{ \App\Http\Controllers\ContactController::HONEYPOT }}" tabindex="-1" autocomplete="off">
                </div>

                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-form.field name="name" label="Your name" :value="$user?->name" required maxlength="100" autocomplete="name" />
                    <x-form.field name="email" label="Email" type="email" :value="$user?->email" required maxlength="255" autocomplete="email" />
                </div>
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-form.select name="topic" label="What is it about?" placeholder="Choose a topic"
                                   :options="collect($topics)->mapWithKeys(fn ($topic) => [$topic->value => $topic->label()])" required />
                    <x-form.field name="order_number" label="Order number (optional)" maxlength="30" placeholder="TS-20261026-K7M2QX" />
                </div>

                <div class="mb-4">
                    <label for="message" class="mb-1 block text-sm font-medium text-stone-700">Message</label>
                    <textarea id="message" name="message" rows="6" required minlength="10" maxlength="2000"
                              @error('message') aria-invalid="true" aria-describedby="message-error" @enderror
                              @class([
                                  'block w-full rounded-md border bg-white px-3 py-2.5 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
                                  'border-red-400' => $errors->has('message'),
                                  'border-stone-300' => ! $errors->has('message'),
                              ])>{{ old('message') }}</textarea>
                    @error('message')
                        <p id="message-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 font-semibold text-white hover:bg-stone-700">Send message</button>
                <p class="mt-3 text-xs text-stone-500">We use your details only to answer you. See our <a href="{{ route('pages.privacy') }}" class="underline">Privacy Policy</a>.</p>
            </form>

            <aside class="space-y-4 text-sm">
                <section class="rounded-xl border border-stone-200 bg-white p-5">
                    <h2 class="font-semibold">Contact us</h2>
                    <dl class="mt-3 space-y-2 text-stone-700">
                        <div><dt class="text-stone-500">Email</dt><dd>{{ config('shop.support.email') }}</dd></div>
                        <div><dt class="text-stone-500">Phone</dt><dd>{{ config('shop.support.phone') }}</dd></div>
                        <div><dt class="text-stone-500">Hours</dt><dd>{{ config('shop.support.hours') }}</dd></div>
                    </dl>
                </section>
                <section class="rounded-xl border border-stone-200 bg-white p-5">
                    <h2 class="font-semibold">Quick help</h2>
                    <ul class="mt-3 space-y-2">
                        <li><a href="{{ route('pages.faq') }}" class="text-emerald-700 hover:underline">FAQ</a></li>
                        <li><a href="{{ route('pages.shipping') }}" class="text-emerald-700 hover:underline">Shipping Policy</a></li>
                        <li><a href="{{ route('pages.returns') }}" class="text-emerald-700 hover:underline">Return & Refund Policy</a></li>
                        @auth
                            <li><a href="{{ route('account.orders.index') }}" class="text-emerald-700 hover:underline">My orders</a> (returns start here)</li>
                        @endauth
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.shop>
