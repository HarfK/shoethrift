<x-layouts.admin :title="'Edit '.$product->name">
    <a href="{{ route('admin.products.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Products</a>
    <div class="mt-2 mb-6">
        <h1 class="text-2xl font-semibold">Edit product</h1>
        <p class="mt-1 text-sm text-stone-500">Slug: {{ $product->slug }}</p>
    </div>

    <form method="POST" action="{{ route('admin.products.update', $product) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('admin.products._form', ['submitLabel' => 'Save changes'])
    </form>
</x-layouts.admin>
