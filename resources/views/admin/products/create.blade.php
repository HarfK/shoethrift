<x-layouts.admin title="Add product">
    <a href="{{ route('admin.products.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Products</a>
    <h1 class="mt-2 mb-6 text-2xl font-semibold">Add product</h1>

    <form method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.products._form', ['submitLabel' => 'Create product'])
    </form>
</x-layouts.admin>
