@use('App\Http\Requests\Admin\ProductRequest')

@php
    $imageErrors = collect($errors->get('images.*'))->flatten()->unique();
    $primaryId = (int) old('primary_image_id', $product->images->firstWhere('is_primary', true)?->id);
    $removeIds = array_map('intval', (array) old('remove_images', []));
@endphp

<div class="grid gap-6 lg:grid-cols-3">
    <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6 lg:col-span-2">
        <h2 class="mb-4 text-base font-semibold">Details</h2>

        <x-form.field name="name" label="Name" :value="$product->name" required maxlength="255" placeholder="e.g. Nike Air Jordan 1" />

        <x-form.field name="brand" label="Brand" :value="$product->brand" required maxlength="100" list="brand-options" placeholder="e.g. Nike" />
        <datalist id="brand-options">
            @foreach ($brands as $brand)
                <option value="{{ $brand }}"></option>
            @endforeach
        </datalist>

        <div class="mb-4">
            <label for="description" class="mb-1 block text-sm font-medium text-stone-700">Description</label>
            <textarea id="description" name="description" rows="5" maxlength="5000"
                      @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
                      @class([
                          'block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
                          'border-red-400' => $errors->has('description'),
                          'border-stone-300' => ! $errors->has('description'),
                      ])>{{ old('description', $product->description) }}</textarea>
            @error('description')
                <p id="description-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid gap-x-4 sm:grid-cols-2">
            <x-form.select name="category_id" label="Category" placeholder="Choose a category" required
                           :options="$categories->pluck('name', 'id')" :value="$product->category_id" />
            <x-form.select name="condition" label="Condition" placeholder="Choose a condition" required
                           :options="collect($conditions)->mapWithKeys(fn ($c) => [$c->value => $c->label()])" :value="$product->condition?->value" />
            <x-form.field name="price" label="Price (RM)" type="number" step="0.01" min="0" required :value="$product->price" />
            <x-form.field name="size" label="Size (UK)" type="number" step="0.5" min="1" max="15" required :value="$product->size" placeholder="e.g. 8 or 8.5" />
            <x-form.field name="color" label="Colour" :value="$product->color" maxlength="50" placeholder="e.g. Black/White" />
            <x-form.field name="stock" label="Stock" type="number" step="1" min="0" required :value="$product->stock" />
            <x-form.select name="status" label="Status" required
                           :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :value="$product->status?->value" />
        </div>
    </section>

    <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="mb-4 text-base font-semibold">Images</h2>

        @if ($product->images->isNotEmpty())
            <fieldset class="mb-5">
                <legend class="mb-2 text-sm text-stone-600">Choose the primary image, or tick images to remove.</legend>
                <ul class="grid grid-cols-2 gap-3">
                    @foreach ($product->images as $image)
                        <li class="rounded-lg border border-stone-200 p-2">
                            @if ($image->url)
                                <img src="{{ $image->url }}" alt="" class="aspect-square w-full rounded-md object-cover">
                            @else
                                <div class="flex aspect-square w-full items-center justify-center rounded-md bg-stone-100 text-xs text-stone-400">File missing</div>
                            @endif
                            <label class="mt-2 flex items-center gap-2 text-sm">
                                <input type="radio" name="primary_image_id" value="{{ $image->id }}" @checked($primaryId === $image->id) class="text-emerald-700">
                                Primary
                            </label>
                            <label class="mt-1 flex items-center gap-2 text-sm text-red-700">
                                <input type="checkbox" name="remove_images[]" value="{{ $image->id }}" @checked(in_array($image->id, $removeIds, true)) class="rounded text-red-700">
                                Remove
                            </label>
                        </li>
                    @endforeach
                </ul>
                @error('primary_image_id')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('remove_images.*')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </fieldset>
        @endif

        <label for="images" class="mb-1 block text-sm font-medium text-stone-700">
            {{ $product->images->isEmpty() ? 'Upload images' : 'Add more images' }}
        </label>
        <input id="images" name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp"
               class="block w-full text-sm text-stone-600 file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-stone-200">
        <p class="mt-1 text-xs text-stone-500">
            JPG, PNG or WebP, up to 5 MB each, {{ ProductRequest::MAX_IMAGES }} images per product.
            @if ($product->images->isEmpty()) The first image becomes the primary one. @endif
        </p>
        @error('images')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
        @foreach ($imageErrors as $message)
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @endforeach
    </section>
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-600/40 focus:ring-offset-2">
        {{ $submitLabel }}
    </button>
    <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-stone-600 hover:underline">Cancel</a>
</div>
