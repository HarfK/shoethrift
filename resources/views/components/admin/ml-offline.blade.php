{{-- Shown instead of ML data when the ML API can't be used. $result: App\Services\Ml\MlResult. --}}
@props(['result'])

<div {{ $attributes->class('rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900') }} role="status" data-ml-offline>
    <p class="font-semibold">ML insights are offline</p>
    <p class="mt-1">{{ $result->error }}</p>
    <p class="mt-2 text-amber-800">The rest of the admin panel works as normal. See <a href="{{ route('admin.insights.status') }}" class="font-medium underline">ML status</a> for details.</p>
</div>
