<x-content-page title="Privacy Policy" intro="How we collect, use and protect your personal data, in line with Malaysia's Personal Data Protection Act 2010 (PDPA).">
    <h2>What we collect</h2>
    <ul>
        <li><strong>Account details:</strong> name, email address, password (stored encrypted), account phone and address.</li>
        <li><strong>Order details:</strong> recipient name, recipient phone, shipping address, the pairs you bought and how you paid.</li>
        <li><strong>Payment references:</strong> HitPay payment IDs. Card and e-wallet details are handled by HitPay; we never receive them.</li>
        <li><strong>Messages and returns:</strong> what you send to Customer Service, and the reasons and photos in return requests.</li>
        <li><strong>Technical data:</strong> cookies needed to keep you logged in and remember your cart.</li>
    </ul>

    <h2>Why we use it</h2>
    <ul>
        <li>To run your account, deliver your orders, take payment, and handle returns and refunds.</li>
        <li>To answer your messages.</li>
        <li>To prevent fraud, for example by limiting Cash on Delivery after repeated failed deliveries.</li>
    </ul>
    <p>We don't sell your data or use it for advertising.</p>

    <h2>Who we share it with</h2>
    <ul>
        <li><strong>HitPay</strong>, to process online payments and refunds.</li>
        <li><strong>Couriers</strong>, who receive the recipient name, phone and address to deliver your parcel.</li>
        <li><strong>Authorities</strong>, only when the law requires it.</li>
    </ul>

    <h2>How long we keep it</h2>
    <p>Order and payment records are kept for as long as Malaysian tax and accounting rules require. Customer Service messages and return photos are kept while they are needed to handle your request.</p>

    <h2>Your rights</h2>
    <p>
        Under the PDPA you can ask to access or correct your personal data, and withdraw consent for its use.
        Email {{ config('shop.support.email') }} or use <a href="{{ route('pages.customer-service') }}">Customer Service</a>.
        We'll reply within 21 days.
    </p>

    <h2>Security</h2>
    <p>Passwords are hashed, payments go through HitPay's secure checkout, and return photos are only visible to you and our staff.</p>

    <h2>Cookies</h2>
    <p>We only use cookies needed for the site to work (your login session, cart and security tokens). No tracking or advertising cookies.</p>
</x-content-page>
