<x-layouts.admin title="Add category">
    <a href="{{ route('admin.categories.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Categories</a>
    <h1 class="mt-2 mb-6 text-2xl font-semibold">Add category</h1>

    <form method="POST" action="{{ route('admin.categories.store') }}">
        @csrf
        @include('admin.categories._form', ['submitLabel' => 'Create category'])
    </form>
</x-layouts.admin>
