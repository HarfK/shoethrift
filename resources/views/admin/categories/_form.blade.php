<section class="max-w-2xl rounded-xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6">
    <x-form.field name="name" label="Name" :value="$category->name" required maxlength="100" placeholder="e.g. Sneakers" />

    <div class="mb-4">
        <label for="description" class="mb-1 block text-sm font-medium text-stone-700">Description</label>
        <textarea id="description" name="description" rows="3" maxlength="1000"
                  @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
                  @class([
                      'block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
                      'border-red-400' => $errors->has('description'),
                      'border-stone-300' => ! $errors->has('description'),
                  ])>{{ old('description', $category->description) }}</textarea>
        @error('description')
            <p id="description-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <p class="text-xs text-stone-500">The URL slug is generated from the name.</p>
</section>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-600/40 focus:ring-offset-2">
        {{ $submitLabel }}
    </button>
    <a href="{{ route('admin.categories.index') }}" class="text-sm font-medium text-stone-600 hover:underline">Cancel</a>
</div>
