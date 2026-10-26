<x-content-page title="Shipping Policy" intro="We ship every pair across Malaysia, cleaned and checked.">
    <h2>Shipping fee</h2>
    <p>A flat <strong>RM {{ number_format(config('shop.shipping_fee'), 2) }}</strong> per order, wherever you are in Malaysia. You see it before you pay.</p>

    <h2>Delivery times</h2>
    <ul>
        <li><strong>Dispatch:</strong> {{ config('shop.delivery.dispatch') }} after your order is paid (or confirmed, for Cash on Delivery).</li>
        <li><strong>West Malaysia:</strong> {{ config('shop.delivery.west_malaysia') }} after dispatch.</li>
        <li><strong>Sabah, Sarawak and Labuan:</strong> {{ config('shop.delivery.east_malaysia') }} after dispatch.</li>
    </ul>
    <p>Delivery times are estimates and may be longer during public holidays and sale periods.</p>

    <h2>Tracking your order</h2>
    <p>Follow each step (placed, paid, shipped, delivered, completed) in <a href="{{ route('account.orders.index') }}">My orders</a>.</p>

    <h2>Online payment</h2>
    <p>
        Online orders are held for {{ config('services.hitpay.payment_window_minutes') }} minutes while you pay.
        If the payment doesn't go through in time, the order is closed and the pairs go back on sale. You won't be charged.
    </p>

    <h2>Cash on Delivery (COD)</h2>
    <ul>
        <li>Pay the courier in cash when your shoes arrive. Please have the exact amount ready.</li>
        <li>If a COD parcel can't be delivered or isn't paid for, it comes back to us and the order is cancelled.</li>
        <li>After {{ config('shop.cod.max_failed_deliveries') }} failed COD deliveries, Cash on Delivery is no longer offered on your account. You can still pay online.</li>
        @if (config('shop.cod.max_total'))
            <li>Cash on Delivery is available for orders up to RM {{ number_format(config('shop.cod.max_total'), 2) }}.</li>
        @endif
    </ul>

    <h2>Wrong address or missing parcel</h2>
    <p>
        Check your recipient name, phone and address at checkout. If your parcel hasn't arrived within the times above,
        <a href="{{ route('pages.customer-service') }}">contact us</a> with your order number and we'll follow up with the courier.
    </p>
</x-content-page>
