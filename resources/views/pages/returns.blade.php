@php($days = config('shop.returns.window_days'))

<x-content-page title="Return & Refund Policy" :intro="'Pre-loved shoes should arrive exactly as described. If they don\'t, you can return them within '.$days.' days of delivery.'">
    <h2>Your rights</h2>
    <p>
        This policy adds to, and does not limit, your rights under Malaysian law, including the Consumer Protection Act 1999
        and the Consumer Protection (Electronic Trade Transactions) Regulations 2012.
    </p>

    <h2>When you can return</h2>
    <ul>
        <li>You can request a return within <strong>{{ $days }} days of delivery</strong>, from the order page in <a href="{{ route('account.orders.index') }}">My orders</a>.</li>
        <li>The order must be <strong>delivered and paid</strong>. Cash on Delivery orders can be returned once the cash has been collected.</li>
        <li>One return request per order. You can choose which pairs in the order to return.</li>
    </ul>

    <h2>Good reasons to return</h2>
    <ul>
        <li>The pair doesn't fit, even though it's the UK size listed.</li>
        <li>The condition or details aren't as described (for example extra wear or a missing insole that wasn't mentioned).</li>
        <li>The pair arrived damaged, or we sent the wrong item.</li>
        <li>You changed your mind (the pair must be unworn and in the condition you received it).</li>
    </ul>
    <p>Adding up to {{ config('shop.returns.max_photos') }} photos with your request helps us decide quickly, especially for condition or damage.</p>

    <h2>How it works</h2>
    <ol>
        <li><strong>Request:</strong> open the order in My orders and choose "Request a return".</li>
        <li><strong>Review:</strong> we approve or reject the request within {{ config('shop.support.reply_within') }}. If we reject it, we'll tell you why.</li>
        <li><strong>Send the pairs back</strong> to the address shown on your order page after approval:
            <span class="mt-1 block whitespace-pre-line rounded-md bg-stone-100 p-3 text-sm">{{ config('shop.returns.address') }}</span>
        </li>
        <li><strong>Refund:</strong> once the pairs arrive and are checked, we refund you.</li>
    </ol>

    <h2>Refunds</h2>
    <ul>
        <li><strong>Online payments</strong> are refunded to the original payment method through HitPay. Your bank may take 5 to 10 working days to show it.</li>
        <li><strong>Cash on Delivery</strong> orders are refunded by bank transfer. We'll ask for your account details.</li>
        <li>We refund the price of the returned pairs. The original shipping fee (RM {{ number_format(config('shop.shipping_fee'), 2) }}) is refunded too if the pair was faulty, not as described or the wrong item.</li>
        <li>If a returned pair comes back worn or damaged by use, we may give a partial refund. We'll explain why.</li>
        <li>Return postage is paid by you, except when the return is our mistake (wrong item, damaged, or not as described). Then we'll refund it with your proof of postage.</li>
    </ul>

    <h2>Cancelled and failed orders</h2>
    <p>
        If a payment reaches us after an order was cancelled or failed, we refund it in full automatically.
        See the <a href="{{ route('pages.shipping') }}">Shipping Policy</a> for failed Cash on Delivery deliveries.
    </p>
</x-content-page>
