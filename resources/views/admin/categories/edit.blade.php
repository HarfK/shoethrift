<x-layouts.admin :title="'Edit '.$category->name">
    <a href="{{ route('admin.categories.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Categories</a>
    <div class="mt-2 mb-6">
        <h1 class="text-2xl font-semibold">Edit category</h1>
        <p class="mt-1 text-sm text-stone-500">Slug: {{ $category->slug }}</p>
    </div>

    <form method="POST" action="{{ route('admin.categories.update', $category) }}">
        @csrf
        @method('PUT')
        @include('admin.categories._form', ['submitLabel' => 'Save changes'])
    </form>
</x-layouts.admin>
