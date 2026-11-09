{{-- Existing library photos for the typed name + colour. Saving decides: the server looks the set up again and uploads always win. --}}
<div id="existing-photos" class="mb-5 hidden rounded-lg border border-stone-200 bg-stone-50 p-3 text-sm" aria-live="polite"
     data-url="{{ route('admin.products.photo-suggestions') }}">
    <input type="hidden" name="use_existing_photos" value="{{ old('use_existing_photos') ? 1 : 0 }}" data-use-input>
    <div data-found class="hidden">
        <h3 class="font-medium text-stone-800">Existing photos for this model and colour</h3>
        <ul data-thumbs class="mt-2 grid grid-cols-4 gap-2"></ul>
        <p data-more class="mt-1 hidden text-xs text-stone-500"></p>
        <button type="button" data-use
                class="mt-2 rounded-md border border-emerald-700 bg-white px-3 py-1.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-50">Use these photos</button>
        <p data-note class="mt-2 hidden text-xs font-medium text-emerald-800"></p>
    </div>
    <p data-none class="hidden text-stone-600">No existing photos found, please upload photos.</p>
</div>
<script>
    // Waits for the page: the upload field comes after this panel.
    document.addEventListener('DOMContentLoaded', () => {
        const box = document.getElementById('existing-photos');
        const form = box.closest('form');
        const input = box.querySelector('[data-use-input]');
        const found = box.querySelector('[data-found]');
        const none = box.querySelector('[data-none]');
        const thumbs = box.querySelector('[data-thumbs]');
        const more = box.querySelector('[data-more]');
        const button = box.querySelector('[data-use]');
        const note = box.querySelector('[data-note]');
        const files = form.elements['images[]'];
        let timer;
        let latest = 0;

        const render = () => {
            const uploading = files.files.length > 0;
            const chosen = input.value === '1';
            button.disabled = uploading;
            button.textContent = chosen ? "Don't use these photos" : 'Use these photos';
            note.textContent = uploading ? 'Your uploaded photos will be used instead.' : 'Using existing photos. Upload your own to replace them.';
            note.classList.toggle('hidden', !uploading && !chosen);
        };

        const lookup = async () => {
            const name = form.elements.name.value.trim();
            const color = form.elements.color.value.trim();
            const ticket = ++latest;
            if (name === '' || color === '') {
                box.classList.add('hidden');
                return;
            }
            try {
                const response = await fetch(box.dataset.url + '?name=' + encodeURIComponent(name) + '&color=' + encodeURIComponent(color), { headers: { 'Accept': 'application/json' } });
                const body = await response.json();
                if (ticket !== latest) return;
                if (!body.found) input.value = '0';
                thumbs.replaceChildren(...body.photos.map((url) => {
                    const item = document.createElement('li');
                    const image = document.createElement('img');
                    image.src = url;
                    image.alt = '';
                    image.className = 'aspect-square w-full rounded-md object-cover';
                    item.append(image);
                    return item;
                }));
                more.textContent = body.more > 0 ? 'and ' + body.more + ' more' : '';
                more.classList.toggle('hidden', body.more === 0);
                found.classList.toggle('hidden', !body.found);
                none.classList.toggle('hidden', body.found);
                box.classList.remove('hidden');
                render();
            } catch (error) {
                box.classList.add('hidden'); // the lookup is optional; uploading still works
            }
        };

        // Editing the name or colour means a different set, so an earlier choice no longer applies.
        ['name', 'color'].forEach((field) => form.elements[field].addEventListener('input', () => {
            input.value = '0';
            clearTimeout(timer);
            timer = setTimeout(lookup, 300);
        }));
        button.addEventListener('click', () => { input.value = input.value === '1' ? '0' : '1'; render(); });
        files.addEventListener('change', render);
        lookup();
    });
</script>
