<x-content-page title="Terms & Conditions" intro="The rules for using ShoeThrift and buying from us.">
    <h2>1. About us</h2>
    <p>{{ config('app.name') }} is an online store for pre-loved shoes in Malaysia. By using this website or placing an order, you agree to these terms.</p>

    <h2>2. Your account</h2>
    <ul>
        <li>Keep your password private. You're responsible for orders placed from your account.</li>
        <li>Give accurate details. We use your name, phone and address to deliver your orders.</li>
        <li>We may suspend accounts used for fraud or abuse, including repeated failed Cash on Delivery orders.</li>
    </ul>

    <h2>3. Products</h2>
    <ul>
        <li>All pairs are second-hand. We describe and photograph their condition honestly; small signs of wear are normal.</li>
        <li>Most pairs are one of a kind. A pair is only yours once your order is placed. Items in your cart aren't reserved.</li>
        <li>Prices are in Ringgit Malaysia (RM). Colours on screen may differ slightly from the real pair.</li>
    </ul>

    <h2>4. Orders and payment</h2>
    <ul>
        <li>Your order is confirmed when it appears in My orders. Online orders must be paid within {{ config('services.hitpay.payment_window_minutes') }} minutes or they are closed.</li>
        <li>Online payments are processed by HitPay. We never see or store your card details.</li>
        <li>We may cancel an order if a pair turns out to be unavailable or mispriced. Any payment is then refunded in full.</li>
    </ul>

    <h2>5. Delivery</h2>
    <p>See our <a href="{{ route('pages.shipping') }}">Shipping Policy</a> for fees, delivery times and Cash on Delivery rules.</p>

    <h2>6. Returns and refunds</h2>
    <p>See our <a href="{{ route('pages.returns') }}">Return & Refund Policy</a>. Nothing in these terms limits your rights under the Consumer Protection Act 1999.</p>

    <h2>7. Acceptable use</h2>
    <p>Don't misuse the website, for example by trying to break its security, scraping it, or posting false information.</p>

    <h2>8. Liability</h2>
    <p>As far as the law allows, our liability for any order is limited to the amount you paid for it.</p>

    <h2>9. Changes and governing law</h2>
    <p>We may update these terms; the date above shows the latest version. These terms are governed by the laws of Malaysia.</p>
</x-content-page>
