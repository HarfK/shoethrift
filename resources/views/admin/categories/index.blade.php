<x-layouts.admin title="Categories">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Categories</h1>
            <p class="mt-1 text-sm text-stone-600">{{ number_format($categories->total()) }} {{ str('category')->plural($categories->total()) }}</p>
        </div>
        <a href="{{ route('admin.categories.create') }}" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
            Add category
        </a>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            <thead class="bg-stone-50 text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Description</th>
                    <th scope="col" class="px-4 py-3 text-right">Products</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($categories as $category)
                    <tr class="align-middle">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.categories.edit', $category) }}" class="font-medium hover:underline">{{ $category->name }}</a>
                            <div class="text-xs text-stone-500">{{ $category->slug }}</div>
                        </td>
                        <td class="max-w-md px-4 py-3 text-stone-600">{{ str($category->description)->limit(80) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            <a href="{{ route('admin.products.index', ['category' => $category->id]) }}" class="hover:underline">{{ $category->products_count }}</a>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('admin.categories.edit', $category) }}" class="font-medium text-emerald-700 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="ml-3 inline"
                                  onsubmit="return confirm(@js('Delete the "'.$category->name.'" category?'))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="font-medium text-red-700 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center text-stone-500">No categories yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $categories->links() }}
    </div>
</x-layouts.admin>
