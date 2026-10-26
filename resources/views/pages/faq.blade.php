@php
    $faqs = [
        'Buying' => [
            'Are the shoes authentic?' => 'Yes. Every pair is checked by hand before it is listed. If a pair turns out not to be as described, you can return it for a full refund.',
            'What do the condition labels mean?' => '"Like new" means worn a few times with almost no marks. "Good" shows light wear. "Fair" shows clear wear that is described and photographed in the listing.',
            'How do I choose my size?' => 'All sizes are UK sizes. Use the size filter on the shop page to see only pairs in your size, and check the description for notes on fit.',
            'Can I reserve a pair?' => 'No. Adding a pair to your cart doesn\'t hold it. Most pairs are one of a kind, so whoever checks out first gets it.',
        ],
        'Payment' => [
            'How can I pay?' => 'Online through HitPay\'s secure checkout (cards and e-wallets), or Cash on Delivery.',
            'How long do I have to pay online?' => 'Your pairs are held for '.config('services.hitpay.payment_window_minutes').' minutes. After that the order closes and you aren\'t charged.',
            'Why can\'t I choose Cash on Delivery?' => 'COD isn\'t offered after '.config('shop.cod.max_failed_deliveries').' failed COD deliveries'.(config('shop.cod.max_total') ? ', or for orders above RM '.number_format(config('shop.cod.max_total'), 2) : '').'. You can still pay online.',
            'Where is my receipt?' => 'Once an order is paid, open it in My orders and choose "View receipt". You can print it or save it as a PDF.',
        ],
        'Delivery' => [
            'How much is shipping?' => 'A flat RM '.number_format(config('shop.shipping_fee'), 2).' per order anywhere in Malaysia.',
            'How long does delivery take?' => config('shop.delivery.west_malaysia').' in West Malaysia and '.config('shop.delivery.east_malaysia').' in East Malaysia, after dispatch.',
            'How do I track my order?' => 'Open it in My orders. It shows each step from placed to delivered.',
        ],
        'Returns & refunds' => [
            'Can I return a pair?' => 'Yes, within '.config('shop.returns.window_days').' days of delivery. Open the order in My orders and choose "Request a return".',
            'When will I get my money back?' => 'After the returned pairs arrive and are checked. Online payments go back to your original payment method; COD orders are refunded by bank transfer.',
        ],
    ];
@endphp

<x-content-page title="FAQ" intro="Quick answers about buying, paying, delivery and returns.">
    @foreach ($faqs as $section => $questions)
        <h2>{{ $section }}</h2>
        <div class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
            @foreach ($questions as $question => $answer)
                <details class="group px-4 py-3">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-stone-900">
                        {{ $question }}
                        <span class="text-stone-400 transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>
                    <p class="mt-2 text-stone-600">{{ $answer }}</p>
                </details>
            @endforeach
        </div>
    @endforeach
</x-content-page>
