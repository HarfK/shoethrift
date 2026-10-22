@props(['title' => null, 'heading' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <a href="{{ route('home') }}" class="mb-8 text-2xl font-semibold tracking-tight">
            {{ config('app.name', 'Thrift Store') }}
        </a>

        <div class="w-full max-w-md rounded-xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
            @if ($heading)
                <h1 class="mb-6 text-xl font-semibold">{{ $heading }}</h1>
            @endif

            @if (session('status'))
                <div class="mb-6 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</body>
</html>
