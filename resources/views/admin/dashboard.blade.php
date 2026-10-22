<x-layouts.admin title="Dashboard">
    <h1 class="text-2xl font-semibold">Dashboard</h1>
    <p class="mt-1 text-sm text-stone-600">Welcome back, {{ auth()->user()->name }}.</p>

    <dl class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $label => $value)
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <dt class="text-sm text-stone-600">{{ $label }}</dt>
                <dd class="mt-2 text-3xl font-semibold tabular-nums">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>
</x-layouts.admin>
