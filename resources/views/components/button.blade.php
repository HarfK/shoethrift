<button {{ $attributes->merge(['type' => 'submit'])->class('inline-flex w-full items-center justify-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-600/40 focus:ring-offset-2') }}>
    {{ $slot }}
</button>
