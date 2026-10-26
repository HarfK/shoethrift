{{--
    Address inputs shared by the address book and checkout's "use a different address".
    $address: Address model (or null), $required: add HTML `required` (off when the fields may be hidden),
    $withLabel: show the optional "Label" field (address book only).
--}}
@php
    $address ??= null;
    $required = $required ?? true;
    $withLabel = $withLabel ?? false;
    $stateOptions = collect(config('shop.states'))->mapWithKeys(fn ($state) => [$state => $state]);
@endphp

<div class="grid gap-x-4 sm:grid-cols-2">
    <x-form.field name="recipient_name" label="Recipient name" :value="$address?->recipient_name" maxlength="100" autocomplete="name" :required="$required" />
    <x-form.field name="phone" label="Recipient phone" type="tel" :value="$address?->phone" maxlength="30" autocomplete="tel" placeholder="012-345 6789" :required="$required" />
</div>
<x-form.field name="line1" label="Address line 1" :value="$address?->line1" maxlength="255" autocomplete="address-line1" placeholder="No. 12, Jalan Bunga Raya" :required="$required" />
<x-form.field name="line2" label="Address line 2 (optional)" :value="$address?->line2" maxlength="255" autocomplete="address-line2" placeholder="Taman Melati" />
<div class="grid gap-x-4 sm:grid-cols-3">
    <x-form.field name="postcode" label="Postcode" :value="$address?->postcode" inputmode="numeric" maxlength="5" autocomplete="postal-code" placeholder="40100" :required="$required" />
    <x-form.field name="city" label="City" :value="$address?->city" maxlength="100" autocomplete="address-level2" placeholder="Shah Alam" :required="$required" />
    <x-form.select name="state" label="State" :value="$address?->state" placeholder="Choose a state" :options="$stateOptions" :required="$required" />
</div>
@if ($withLabel)
    <x-form.field name="label" label="Label (optional)" :value="$address?->label" maxlength="50" placeholder="Home, Office…" />
@endif
