@use('App\Http\Requests\Admin\ProductRequest')

@php
    $imageErrors = collect($errors->get('images.*'))->flatten()->unique();
    $primaryId = (int) old('primary_image_id', $product->images->firstWhere('is_primary', true)?->id);
    $removeIds = array_map('intval', (array) old('remove_images', []));
@endphp

<div class="grid gap-6 lg:grid-cols-3">
    <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6 lg:col-span-2">
        <h2 class="mb-4 text-base font-semibold">Details</h2>

        <x-form.field name="name" label="Name" :value="$product->name" required maxlength="255" placeholder="e.g. Nike Air Jordan 1"
                      list="name-options" autocomplete="off" data-suggest-url="{{ route('admin.products.name-suggestions') }}" />
        <datalist id="name-options"></datalist>

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
            <x-form.field name="retail_price" label="Retail price (RM)" type="number" step="0.01" min="0" :value="$product->retail_price" placeholder="Original price new (optional)" />
            <x-form.field name="size" label="Size (UK)" type="number" step="0.5" min="1" max="15" required :value="$product->size" placeholder="e.g. 8 or 8.5" />
            <div>
                <x-form.field name="color" label="Colour" :value="$product->color" maxlength="50" placeholder="e.g. Black/White"
                              list="color-options" autocomplete="off" data-suggest-url="{{ route('admin.products.color-suggestions') }}" />
                <datalist id="color-options"></datalist>
                <div id="color-chips" class="-mt-2 mb-4 flex flex-wrap gap-1.5 empty:hidden"></div>
                <p id="color-hint" class="-mt-2 mb-4 hidden text-xs font-medium text-amber-800">New colour. Upload photos for it.</p>
            </div>
            <x-form.field name="stock" label="Stock" type="number" step="1" min="0" required :value="$product->stock" />
            <x-form.select name="status" label="Status" required
                           :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :value="$product->status?->value" />
        </div>

        {{-- Thrift details: what buyers of second-hand shoes ask about. All optional. --}}
        <fieldset class="mt-4 border-t border-stone-200 pt-5">
            <legend class="text-base font-semibold">Thrift details</legend>
            <div class="mt-3 grid gap-x-4 sm:grid-cols-3">
                <x-form.select name="material" label="Material" placeholder="Not specified"
                               :options="collect(config('shop.materials'))->mapWithKeys(fn ($m) => [$m => $m])" :value="$product->material" />
                <x-form.field name="insole_length_cm" label="Insole length (cm)" type="number" step="0.1" min="10" max="35"
                              :value="$product->insole_length_cm" placeholder="e.g. 27.5" />
                <x-form.select name="sole_condition" label="Sole condition" placeholder="Not specified"
                               :options="collect(\App\Enums\SoleCondition::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])" :value="$product->sole_condition?->value" />
            </div>
            <label class="mb-4 flex items-center gap-2 text-sm">
                <input type="hidden" name="box_included" value="0">
                <input type="checkbox" name="box_included" value="1" class="rounded border-stone-300 text-emerald-700"
                       @checked(old('box_included', $product->box_included))>
                Original box included
            </label>
            @foreach (['wear_notes' => ['Defects or wear notes', 'e.g. Small scuff on the left heel, light creasing on the toe box.'], 'authenticity_notes' => ['Authenticity notes', 'e.g. Style code on the tongue label checked; stitching matches retail.']] as $field => [$label, $placeholder])
                <div class="mb-4">
                    <label for="{{ $field }}" class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</label>
                    <textarea id="{{ $field }}" name="{{ $field }}" rows="3" maxlength="1000" placeholder="{{ $placeholder }}"
                              @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror
                              @class([
                                  'block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
                                  'border-red-400' => $errors->has($field),
                                  'border-stone-300' => ! $errors->has($field),
                              ])>{{ old($field, $product->{$field}) }}</textarea>
                    @error($field)
                        <p id="{{ $field }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </fieldset>

        {{-- ML price suggestion (POST admin.products.suggest-price -> ML API). Nothing is saved; the admin decides. --}}
        <section id="price-suggestion" class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4"
                 data-url="{{ route('admin.products.suggest-price') }}" aria-labelledby="price-suggestion-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="price-suggestion-heading" class="text-sm font-semibold text-emerald-900">Price suggestion</h2>
                    <p class="text-xs text-emerald-900/80">Uses the details above (name, brand, category, condition, size, retail price…). Nothing is saved until you save the product.</p>
                </div>
                <button type="button" data-suggest-price
                        class="rounded-md border border-emerald-700 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-50">Suggest price</button>
            </div>
            <div data-suggest-result class="mt-3 hidden text-sm" aria-live="polite"></div>
        </section>
        <script>
            // Name suggestions: picking one fills Brand and Category only if they are empty. Free typing still works.
            (() => {
                const name = document.getElementById('name');
                const list = document.getElementById('name-options');
                const form = name.closest('form');
                let known = new Map();
                let timer;
                let latest = 0;

                const fillFromPick = () => {
                    const pick = known.get(name.value.trim().toLowerCase());
                    if (!pick) return;
                    if (!form.elements.brand.value.trim()) form.elements.brand.value = pick.brand;
                    if (!form.elements.category_id.value) form.elements.category_id.value = pick.category_id;
                };

                name.addEventListener('input', () => {
                    fillFromPick();
                    clearTimeout(timer);
                    const q = name.value.trim();
                    const ticket = ++latest;
                    if (q === '') { list.replaceChildren(); return; }
                    timer = setTimeout(async () => {
                        try {
                            const response = await fetch(name.dataset.suggestUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                            const body = await response.json();
                            if (ticket !== latest) return;
                            known = new Map(body.suggestions.map((s) => [s.name.toLowerCase(), s]));
                            list.replaceChildren(...body.suggestions.map((s) => { const o = document.createElement('option'); o.value = s.name; return o; }));
                        } catch (error) { /* suggestions are optional; typing still works */ }
                    }, 200);
                });
            })();

            // Colour suggestions for the model in Name. Clicking a chip fires "input", which also runs the photo lookup.
            (() => {
                const name = document.getElementById('name');
                const color = document.getElementById('color');
                const chips = document.getElementById('color-chips');
                const list = document.getElementById('color-options');
                const hint = document.getElementById('color-hint');
                let timer;
                let latest = 0;

                const show = (colors, isNew) => {
                    list.replaceChildren(...colors.map((c) => { const o = document.createElement('option'); o.value = c; return o; }));
                    chips.replaceChildren(...colors.map((c) => {
                        const chip = document.createElement('button');
                        chip.type = 'button';
                        chip.className = 'rounded-full border border-stone-300 bg-white px-2.5 py-0.5 text-xs font-medium text-stone-700 hover:bg-stone-100';
                        chip.textContent = c;
                        chip.addEventListener('click', () => {
                            color.value = c;
                            color.dispatchEvent(new Event('input', { bubbles: true }));
                            color.focus();
                        });
                        return chip;
                    }));
                    hint.classList.toggle('hidden', !isNew);
                };

                const refresh = async () => {
                    const model = name.value.trim();
                    const typed = color.value.trim();
                    const ticket = ++latest;
                    if (model === '') { show([], false); return; }
                    try {
                        const response = await fetch(color.dataset.suggestUrl + '?name=' + encodeURIComponent(model) + '&q=' + encodeURIComponent(typed), { headers: { 'Accept': 'application/json' } });
                        const body = await response.json();
                        if (ticket !== latest) return;
                        show(body.colors, typed !== '' && body.colors.length === 0);
                    } catch (error) {
                        if (ticket === latest) show([], false); // suggestions are optional; typing still works
                    }
                };

                [name, color].forEach((field) => field.addEventListener('input', () => {
                    clearTimeout(timer);
                    timer = setTimeout(refresh, 200);
                }));
                refresh();
            })();

            (() => {
                const box = document.getElementById('price-suggestion');
                const form = box.closest('form');
                const button = box.querySelector('[data-suggest-price]');
                const out = box.querySelector('[data-suggest-result]');
                const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
                const rm = (v) => 'RM' + Number(v).toLocaleString('en-MY', { maximumFractionDigits: 0 });

                const show = (nodes) => { out.replaceChildren(...nodes); out.classList.remove('hidden'); };
                const showErrors = (message, errors) => {
                    const list = el('ul', 'mt-1 list-disc pl-5');
                    (errors || []).forEach((e) => list.append(el('li', '', e)));
                    const wrap = el('div', 'rounded-md border border-red-200 bg-red-50 p-3 text-red-800');
                    wrap.append(el('p', 'font-medium', message || 'The price could not be suggested.'), list);
                    show([wrap]);
                };

                button.addEventListener('click', async () => {
                    const data = new FormData(form);
                    const value = (name) => (data.get(name) ?? '').toString().trim();
                    const payload = {
                        name: value('name'), brand: value('brand'), category_id: value('category_id'), condition: value('condition'),
                        size: value('size'), color: value('color') || null, material: value('material') || null,
                        sole_condition: value('sole_condition') || null, retail_price: value('retail_price') || null,
                        box_included: form.querySelector('input[type=checkbox][name=box_included]')?.checked ?? false,
                    };
                    button.disabled = true;
                    button.textContent = 'Suggesting…';
                    try {
                        const response = await fetch(box.dataset.url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                       'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                            body: JSON.stringify(payload),
                        });
                        const body = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            // Laravel validation: {errors: {field: [..]}}; ML API: {errors: [..]}
                            const errors = Array.isArray(body.errors) ? body.errors : Object.values(body.errors || {}).flat();
                            showErrors(body.message, errors);
                            return;
                        }
                        const head = el('div', 'flex flex-wrap items-baseline gap-x-3 gap-y-1');
                        head.append(el('span', 'text-2xl font-semibold text-emerald-900', rm(body.suggested_price)),
                                    el('span', 'text-stone-700', '80% range ' + rm(body.range_80.lower) + ' – ' + rm(body.range_80.upper)));
                        const use = el('button', 'ml-auto rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800', 'Use this price');
                        use.type = 'button';
                        use.dataset.usePrice = '';
                        use.addEventListener('click', () => {
                            const price = form.querySelector('[name=price]');
                            price.value = body.suggested_price;
                            price.dispatchEvent(new Event('input', { bubbles: true }));
                            // The API estimated the retail price (field was empty): keep it, so the product has one for retraining.
                            const retail = form.querySelector('[name=retail_price]');
                            if (body.retail_price_estimated && retail && !retail.value.trim()) {
                                retail.value = Number(body.retail_price_used).toFixed(2);
                                retail.dispatchEvent(new Event('input', { bubbles: true }));
                                use.textContent = 'Price and retail price filled in (not saved yet)';
                            } else {
                                use.textContent = 'Price filled in (not saved yet)';
                            }
                        });
                        head.append(use);

                        const parts = [head];
                        (body.warnings || []).forEach((w) => parts.push(el('p', 'mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 font-medium text-amber-900', '⚠ ' + w)));
                        const list = el('ul', 'mt-2 space-y-0.5 text-stone-700');
                        (body.reasons || []).forEach((r, i) => list.append(el('li', i === 0 ? 'font-medium' : 'pl-3', r)));
                        list.append(el('li', 'border-t border-emerald-200 pt-1 font-medium', '= ' + rm(body.model_estimate) + ', rounded to ' + rm(body.suggested_price)));
                        parts.push(list);
                        (body.notes || []).forEach((n) => parts.push(el('p', 'mt-2 text-xs text-stone-600', n)));
                        if (body.retail_price_estimated) {
                            parts.push(el('p', 'mt-1 text-xs text-stone-600', '"Use this price" also fills Retail price with this estimate (' + rm(body.retail_price_used) + '); change it if you know the real price.'));
                        }
                        if (body.hint) {
                            parts.push(el('p', 'mt-2 rounded-md bg-stone-100 px-3 py-2 text-xs text-stone-700', 'Tip: ' + body.hint));
                        }
                        show(parts);
                    } catch (error) {
                        showErrors('The price suggestion service could not be reached. You can still set the price yourself.', []);
                    } finally {
                        button.disabled = false;
                        button.textContent = 'Suggest price';
                    }
                });
            })();
        </script>
    </section>

    <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="mb-4 text-base font-semibold">Images</h2>

        @if ($product->usesStockPhotos())
            <p class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900" data-stock-photo-warning>
                This product uses stock photos. Upload real photos of this pair.
            </p>
        @endif

        @if ($product->images->isEmpty())
            @include('admin.products._existing-photos')
        @endif

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
            @if ($product->usesStockPhotos()) New uploads replace the stock photos. @endif
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
