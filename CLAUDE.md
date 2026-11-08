# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**ShoeThrift** is a final-year project: a web-based thrift store for second-hand **shoes** (not clothing) in Malaysia, with a rule-based personalised recommendation engine (see "Recommendations" below). Prices are in RM, and FPX payment is planned. Recommendations must be deterministic rules: show available products with the **same UK size / same category / same brand** as the viewed product. No machine learning in the recommendations. Separately, the project has an **ML module** (`ml-service/`, Python) for admin features only: revenue forecasting, restock prediction and price recommendation (see "ML module" below). Keep the code consistent with the proposal's modules (auth, profile, admin products, browse/search, recommendations, cart, checkout, order tracking, admin reports) and flag any divergence.

A thrift store system built on Laravel 12 (PHP ^8.2). So far the app has the database layer, hand-written session auth, an admin area for products, categories and orders (`/admin/...`), and the public shop: home (`/`), shop listing (`/shop`) and product page (`/shop/{slug}`). It also has a login-only cart (`/cart`) where each pair is held for 15 minutes, checkout (`/checkout`, orders are placed as Pending), "My orders" (`/account/orders`), content pages with a Customer Service contact form, return/refund requests, an admin dashboard, sales reports (`/admin/reports`, PDF/CSV), an admin notification bell, a customer account area (profile with avatar, email verification, demo phone OTP, address book), product reviews by verified buyers, rule-based "You may also like" recommendations, and thrift-specific product details. It also has personal recommendations (saved shoe preferences, a home page "For you" block). It also has loyalty points (earn on completed orders, redeem at checkout, expiry, admin adjustments). Customer-side cancel doesn't exist yet. The frontend tooling is Vite 7 + Tailwind CSS 4 via `laravel-vite-plugin` (`resources/css/app.css`, `resources/js/app.js`).

## Commands

```bash
composer setup              # install deps, create .env, key:generate, migrate, npm install + build
composer dev                # runs serve + queue:listen + pail (logs) + vite concurrently
composer test               # config:clear then php artisan test

php artisan test --filter=SomeTest            # run a single test class or method
php artisan test tests/Feature/ExampleTest.php
php artisan test --testsuite=Unit

php artisan migrate:fresh --seed              # rebuild the schema and seed the dev DB
vendor/bin/pint                               # format PHP (Laravel Pint); add --test to check only
npm run build                                 # production asset build
```

**Running everything locally (3 services + scheduler):**
1. **MySQL:** start it in the XAMPP control panel (database `thriftstore`).
2. **Laravel:** `composer dev` (serve + queue + logs + Vite), or `php artisan serve` with `npm run build`.
3. **ML API:** `ml-service\run_api.bat` (FastAPI on 127.0.0.1:8001). Without it the admin Insights pages show "offline"; the rest of the site works.
4. **Scheduler:** `php artisan schedule:work` runs unpaid-order expiry, cart-hold release (every minute) and the weekly `ml:retrain` (Sunday 03:00 Malaysia time). On a server: `php artisan schedule:run` every minute.

## Database: dev vs. test drivers differ

- The local dev database is **MySQL** (`DB_CONNECTION=mysql`, database `thriftstore`, configured in `.env`).
- `phpunit.xml` overrides this to **SQLite `:memory:`** for tests. Migrations and queries must therefore run on both MySQL and SQLite. Avoid raw MySQL-only SQL in migrations; use the schema builder (`enum`, `foreignId()->constrained()`, etc.), which Laravel translates for both drivers.
- The default queue, cache and session drivers are database-backed (the `cache`/`jobs` tables come from the stock `0001_01_01_*` migrations), so `migrate` must have run before the app or `composer dev` will work.

## Domain model

The tables are users → orders → order_items ← products → product_images, plus categories → products.

- Status-like columns are DB `enum`s and are cast to PHP backed enums in `app/Enums` (`UserRole`, `ProductCondition`, `ProductStatus`, `OrderStatus`). Compare against enum cases, not strings. The migrations hardcode the value lists, so adding a case means a new migration too.
- `users.role` is **intentionally not in `$fillable`** because it would allow privilege escalation via mass assignment. Set it explicitly instead (`$user->role = UserRole::Admin`); factories run unguarded, so the `admin()` state works.
- `order_items.price` is the unit price at purchase time and is not linked to `products.price`. `orders.total` is stored, not computed, so keep it in sync with the items when creating orders.
- FK delete rules: deleting a category, or a product that appears in an order, is blocked (restrict); deleting a user who has orders is also blocked. Product images and order items cascade with their parent.
- Shoe attributes on `products`: `brand` (indexed plain string, as in the proposal's ERD; no brands table), `size` (UK size stored as `decimal(3,1)` so "same size" is an exact match; validated as 1–15 in 0.5 steps; display it with `$product->size_label`, e.g. "UK 8.5"), and an optional `color`. Product names include the brand ("Nike Air Jordan 1").
- Seed data comes from `ProductFactory::CATALOG` ([brand, model, category] tuples). Its category names must match `CategorySeeder`: Sneakers, Running, Casual, Boots, Formal, Sandals.
- Each product should have exactly one `is_primary` image (`Product::primaryImage()`). Use the `Product::available()` scope for buyable items (available and in stock) and `visible()` for anything not hidden.
- Seed data: `admin@thriftstore.test` / `password` (admin) and `customer@thriftstore.test` / `password`. `OrderSeeder` decrements stock and marks products sold for non-cancelled orders, and adds `cod.blocked@thriftstore.test` / `password`, a customer with 2 failed COD deliveries (COD is blocked at checkout). It also gives `customer@thriftstore.test` an online order delivered yesterday, so returns can be tried, and `ContactMessageSeeder` adds sample messages.

## Auth and admin area

- Auth is hand-written: no Breeze, Fortify or Jetstream, and no Alpine/React/Vue. Views are Blade + Tailwind 4. The controllers are in `app/Http/Controllers/Auth`, and login validation plus rate limiting (5 attempts per email+IP) is in `app/Http/Requests/Auth/LoginRequest.php`.
- `LoginController::homeFor($user)` is the single source of truth for where a user lands: `/admin` for admins, `/` (`home`) for customers. It's used after login, and through `redirectUsersTo()` in `bootstrap/app.php` for signed-in users hitting guest-only pages. Logout redirects to `/login`, per the proposal's test plan.
- Admin routes go in the `auth` + `admin` route group in `routes/web.php`, with names prefixed `admin.`. The `admin` middleware alias (`EnsureUserIsAdmin`, registered in `bootstrap/app.php`) returns 403 for non-admins. Sidebar links are defined at the top of `resources/views/components/layouts/admin.blade.php`. On md+ the sidebar is `sticky top-0 h-screen`; the menu (`flex-1 min-h-0 overflow-y-auto`) scrolls on its own and the account block (name, View store, Log out) is pinned at the bottom (`mt-auto shrink-0`). On phones it is a top bar, and View store / Log out appear below the page (`AdminLayoutTest`).
- Pages use anonymous component layouts: `<x-layouts.guest>` (auth card) and `<x-layouts.admin>` (sidebar). Forms use `<x-form.field>` (label + input + error) and `<x-button>`. The `<head>` is shared via `partials/head`.
- **Mail: use `MAIL_MAILER=log`.** Password-reset and email-verification emails are written to `storage/logs/laravel.log`, with no account needed. `.env.example` has a commented Mailtrap SMTP block (`sandbox.smtp.mailtrap.io:2525`) for viewing emails in a web inbox; never commit real credentials.
- **Registration** (`RegisterController`) asks for name, email, **phone** and password, but no address (addresses are added later in the address book).
  - **One account per phone number:** `users.phone_normalized` (e.g. `60123456789`, unique index) is filled by a `saving` hook in `User`, using `User::normalizePhone()`. Validation uses `User::uniquePhoneRule($ignoreId)`, on both registration and the profile. `users.phone` keeps the number as typed. Address-book phones are not unique.
  - **Login:** the page has "Remember me" (`remember` → `Auth::attempt(..., $remember)`). The reset route must stay named `password.reset`, because Laravel's reset notification links to it.
- Admin CRUD pattern (see `Admin/ProductController`): a resource controller; Form Requests in `app/Http/Requests/Admin` with shared rules in an abstract base (`ProductRequest`); a shared `_form` partial for create/edit; `<x-form.select>` for enum and relation dropdowns; `back()->with('success'|'error', ...)` for flash banners, which the admin layout renders. Enums expose `label()` for display.

## Public shop

- Public pages use `<x-layouts.shop>` (header with search, cart icon and account `<details>` menu; footer). Its footer categories come from a view composer in `AppServiceProvider`. Products render with `<x-product-card>`, and `<x-shoe-placeholder>` is shown when there is no photo.
- Visibility rules: listings use `Product::available()` (status available and stock > 0). The product page uses `Product::visible()`, so sold items stay reachable with a "Sold" label and hidden ones 404. On a loaded model, use `$product->isAvailable()`.
- `ShopController::filters()` parses the query string defensively (invalid or array values are ignored, never 500). Category filters by slug; size compares numerically against `decimal(3,1)`; use `Product::formatSize()` for size strings. Filter dropdowns only offer values that have available stock.
- The interactive bits work without JS: mobile menu/account menu are `<details>`, the mobile filter panel is a checkbox + `peer-checked`, the gallery is a CSS scroll-snap strip with `#photo-N` anchors. The only JS is `onchange` submit on the sort select (with a `<noscript>` button).
- "Log in to reserve this pair" (product page) links to `/login?return=/shop/{slug}`. `LoginController::create` stores it as the intended URL, but only for same-site relative paths (no `//`, no backslashes), to prevent open redirects.
- Headless Chrome can't render below ~500px wide. To screenshot phone widths, load the page in a 390px `<iframe>` inside a local HTML file.

## Cart and reservations

- **Login required.** Cart routes are in the `auth` group; guests see "Log in to reserve this pair" (`/login?return=/shop/{slug}`). There is no guest/session cart and no merge on login. Always go through `App\Services\ShoppingCart` (logged-in carts in `carts`/`cart_items`, matching the proposal ERD), never the models directly.
- **Each cart line is a timed hold** (`cart_items.reserved_until`), managed only by `App\Services\Cart\Reservations`:
  - Hold length `shop.cart.hold_minutes` (`CART_HOLD_MINUTES`, default 15), set when the line is created and **never extended** (re-adding, changing quantity, viewing the cart or checkout keep it).
  - Availability for a customer = stock − active holds by **others** (`reserved_until > now`). Expired lines never count, even before cleanup, so correctness doesn't depend on the scheduler. Listings use `Product::withHeldByOthers($userId)` + `isReservedByOthers()` ("Reserved" badge).
  - Race safety: `reserve()`/`update()` lock the product row first; `PlaceOrder` locks the same rows and rejects pairs held by others.
  - A quantity change on a line whose hold ended throws (never silently re-holds).
  - Limits: at most `shop.cart.max_items` (3) pairs per cart (total quantity); adding is throttled by the `cart-add` rate limiter (`shop.cart.adds_per_minute`, 10).
- **Releases:** `releaseExpired()` runs every minute (`cart:release-expired`, `routes/console.php`) **and** lazily on shop page views and before each reserve. Lines are selected `FOR UPDATE` and deleted in the same transaction, so each release is recorded exactly once in `cart_releases` with a reason: `expired`, `bought_by_other`, `no_longer_for_sale` (`CartRelease::message()`). Removing a line yourself, or buying it, is not a release. Deleting a product records releases for everyone holding it.
- **Never silently empty the cart:** `reconcile()` (shop layout view composer) releases, clamps quantities, then turns unseen `cart_releases` into `CartNotice`s (marked `notified_at`, so each shows once), with a "See other UK x shoes" link and up to 3 similar available pairs (`SimilarProducts`). A pair in the customer's **own** open order says "is already in your order TS-…" instead. Notices are *pulled* (`pullNotices()`); `ShoppingCart` is bound `scoped`.
- **UI:** the product page shows "Reserved for you until X" to the holder, and "Reserved by another shopper… may be available again around X" plus "Notify me when it's free" to others. The cart shows a per-line countdown (`data-hold-countdown`, tiny inline script that reloads at 0).
- **Notify me:** `product_watches`; `ProductWatchers::notifyIfFree()` (after commit) emails `ProductAvailableAgain` once when the pair is available and not held (hold ended, removed, order cancelled/failed, return restock), then deletes the watch.
- **Checkout:** an expired own hold → `ReservationExpiredException` → back to the cart with "Your reservation ran out before the order was placed, so nothing was ordered or charged…". Placing the order deletes the holds inside the transaction; the pending order then keeps the stock (online payment window, COD flow) as before.
- **Admin:** `/admin/reservations` (read-only: active holds, customers with expired holds in `shop.cart.expired_window_days` (30)); the order page shows "Expired cart holds" with a "Repeated" badge at ≥ `shop.cart.flag_expired_after` (3). Awareness only, nothing is blocked.
- When checkout fails with `ItemsUnavailableException`, it reconciles the cart and flashes notices as `cart_notices`, merged in on the next page. Cart routes take `{productId}` (not model binding) so removing a since-deleted product still works.

## Checkout

- `App\Services\Checkout\PlaceOrder` is the only way orders are created from a cart. In one transaction it locks the cart's products (`lockForUpdate`, ordered by id to avoid deadlocks), re-checks availability and stock, prices everything from the locked rows in integer sen, creates the order and items, reduces stock (0 → `sold`), and throws `ItemsUnavailableException` / `PriceChangedException` (which roll everything back).
- The browser's `expected_total` is only **compared** with the server total and never used. Totals are `subtotal + shipping_fee`. The flat fee lives in `config/shop.php` and is stored on each order with `shipping_name`/`shipping_phone`; the `$order->subtotal` accessor derives the items total.
- The real concurrency behaviour (a second buyer blocks on the row lock, then is rejected) can't be tested on in-memory SQLite. It was verified manually on MySQL with two parallel PHP processes.
- Customers only reach orders through `$request->user()->orders()`, so another customer's order number 404s. Order numbers come from `Order::generateOrderNumber()` (`TS-YYYYMMDD-XXXXXX`, no ambiguous characters).
- Phone validation: `CheckoutController::PHONE_PATTERN` (Malaysian formats). `UserFactory`/`OrderFactory` generate matching numbers.
- Two different phones, always labelled: `users.phone` is the **"Account phone"** (profile), and `orders.shipping_phone` is the **"Recipient phone"** (shipping). The admin order page shows both.

## Payments (HitPay + Cash on Delivery)

- Built from the official docs (docs.hitpayapp.com): `POST /v1/payment-requests` (form-encoded, `X-BUSINESS-API-KEY` + `X-Requested-With` headers) and `GET /v1/payment-requests/{id}`, all in `App\Services\Payments\HitPayClient`. Config is `config('services.hitpay.*')`, read from `.env` (`HITPAY_API_KEY`, `HITPAY_SALT`, `HITPAY_API_URL`, `HITPAY_CURRENCY`, `HITPAY_PAYMENT_WINDOW_MINUTES`). `phpunit.xml` sets fake values, and `TestCase` calls `Http::preventStrayRequests()`, so tests must use `Http::fake()`.
- **An online order becomes `paid` only in `HitPayWebhookController`:** it verifies `Hitpay-Signature` = HMAC-SHA256(raw body, `HITPAY_SALT`), re-fetches the payment request from the API, and checks status `completed` + a `succeeded` payment + amount/currency/reference equal to the order, then calls `Order::recordGatewayPayment()`. That method locks the order and no-ops if `paid_at` is already set (idempotent); on a cancelled order it records the payment and `needsRefund()` becomes true. Never mark paid from the redirect/query string. `webhooks/hitpay` is CSRF-exempt in `bootstrap/app.php`.
- `HITPAY_SALT` must be the salt of the **webhook endpoint** (Developers → Webhook Endpoints, event `payment_request.completed`), not the API-key salt. HitPay only calls public HTTPS URLs, so local testing needs a tunnel. The deprecated `webhook` request parameter (legacy `hmac` field signing) is deliberately not used.
- Status rules depend on `orders.payment_method` (`Order::allowedTransitionsFrom()`): online pending → cancelled only (paid comes from the webhook); COD has its own flow (see the Cash on Delivery section below); null (older orders) uses the generic rules.
- The HitPay request is created **after** the order transaction commits (`StartHitPayPayment`; it reuses `payment_url` on "Pay now").
- **`App\Services\Payments\SyncHitPayPayment` is the single place that turns HitPay's answer into Paid or Failed.** `refresh()` GETs the payment request and `apply()` acts on it:
  - completed + succeeded + matching amount/currency/reference → `recordGatewayPayment()`;
  - request `failed` / `expired` / `canceled` / `inactive` → `Order::markPaymentFailed()`, which restores stock;
  - `pending` → nothing. A declined card stays pending at HitPay so the customer can retry.
- **Callers of `SyncHitPayPayment`:** the webhook (primary path), the order status page (each load, throttled to one HitPay call per 10 s per order via the cache), "Check again" (unthrottled), and `orders:expire-unpaid`. Every path locks and re-reads the order, so they can't double-apply.
- **Failed status:** an online payment that didn't go through (HitPay finished unpaid, or the 30-minute window ran out). It's set only by the system, never offered to admins, and is distinct from Cancelled, which means the shop cancelled the order. A payment arriving after Cancelled/Failed is recorded and flagged by `needsRefund()`.
- **`orders:expire-unpaid`:** scheduled every minute in `routes/console.php`; locally, run `php artisan schedule:work`. It syncs each order whose `payment_expires_at` has passed; if HitPay still says pending (or the payment never started), it marks the order Failed. If HitPay is unreachable, it skips the order.
- The status page auto-refreshes with `<meta http-equiv="refresh">` and a `?check=N` counter (`AccountOrderController::REFRESH_SECONDS` / `MAX_AUTO_CHECKS`). After that it shows "Pay now" / "Check again". Pages add head tags with `@push('head')` (the shop layout has `@stack('head')`).
- **Receipts:** `/account/orders/{n}/receipt` for orders where `Order::hasReceipt()` is true. PDF is via the browser's print dialog; the shop layout's header, footer and banners are `print:hidden`.
- **Local test tool:** "Simulate payment success" (`account.orders.simulate`) is shown only when `APP_ENV=local`, and the controller returns 404 otherwise. It records `gateway_payment_id = SIMULATED-…`, and `isSimulatedPayment()` labels it on the order, receipt and admin pages. In tests, switching env to local re-enables CSRF, so those tests use `withoutMiddleware(ValidateCsrfToken::class)`.
- **ngrok:** `bootstrap/app.php` trusts proxies `127.0.0.1` and `::1` only, so links become `https://<tunnel>/…` behind ngrok and spoofed forwarded headers from other IPs are ignored. HitPay's `redirect_url` follows the address the customer is using. `HITPAY_PUBLIC_URL` is used only by `php artisan hitpay:webhook-url`, which prints the URL to register in the dashboard. Through ngrok, use `npm run build` (not the Vite dev server) and browse via the tunnel URL, because login cookies are per host.
- Sandbox findings (Singapore sandbox, Oct 2026): MYR and SGD are accepted as currencies; `fpx` is rejected ("unavailable for your account"); no payment methods were enabled on the account, so every explicit method code was rejected. That's why `payment_methods` is omitted and HitPay offers whatever the account has enabled.

## Cash on Delivery

- **Flow:** pending → shipped → **delivered** → completed. COD completed means "Cash collected": it records `paid_at` and the order gets a receipt. `Delivered` is a COD-only status, and COD orders never use `Paid`.
- **Failed delivery:** cancelling a COD order from shipped or delivered sets `orders.delivery_failed_at`, restores stock and counts against the customer. Cancelling while still pending is a shop cancel and isn't counted. See `Order::cancellingMeansFailedDelivery()`.
- **Labels:** show statuses with `$order->statusLabel()` ("Cash collected", "Failed delivery") and `<x-order-status-badge :order="$order" />`, not `$status->label()`. Admin buttons use `$order->actionLabel($to)`.
- **Limits:** `App\Services\Checkout\CashOnDeliveryPolicy` is the single source of truth, configured in `config/shop.php` `cod`:
  - `max_failed_deliveries` (default 2, `SHOP_COD_MAX_FAILED_DELIVERIES`) blocks COD for customers with that many failed deliveries;
  - `max_total` (`SHOP_COD_MAX_TOTAL`, empty = no limit) caps the COD order total.
  - Checkout greys out COD with the reason, and `PlaceOrder` re-checks inside its transaction (`CashOnDeliveryNotAllowedException` → validation error on `payment_method`).
- **Failed COD count:** `User::failedCodCount()` / `isCodBlocked()`, counted from orders (scope `Order::failedCodDelivery()`), never stored. The admin orders list preloads it as `failed_cod_count`. Admins see it on the order page and as a badge in the list.

## Content pages and Customer Service

- **Footer pages:**
  - Static Blade views in `resources/views/pages`, registered with `Route::view` and named `pages.*` (returns, shipping, faq, terms, privacy), all wrapped in `<x-content-page>`.
  - The policy text is a **draft for the FYP, not legal advice**. It cites the Consumer Protection Act 1999, the e-trade regulations and PDPA 2010.
  - Every number on these pages comes from config (`shop.shipping_fee`, `shop.returns.*`, `shop.cod.*`, `shop.delivery.*`, `shop.support.*`, HitPay payment window). Never hardcode them in a page.
- **Customer Service form:**
  - Lives at `/customer-service` (`ContactController`) and saves rows in `contact_messages` (guests allowed, `user_id` nullable).
  - Spam protection: a honeypot field (`ContactController::HONEYPOT`, filled = silently dropped) plus `throttle:5,1`.
  - Admins handle messages at `/admin/messages`: opening one sets `read_at`, and "Mark resolved" sets `resolved_at`. Replies go by `mailto:`, so no email is sent.
- The admin sidebar shows counts for unread messages and open returns, computed in `components/layouts/admin.blade.php`.

## Returns and refunds

- **Eligibility:** `Order::returnUnavailableReason()` is the single source of truth. A return needs:
  - status **Completed** (online = delivered; COD = cash collected) with `paid_at` and `delivered_at` set;
  - a request within `shop.returns.window_days` (7) of `delivered_at`;
  - no earlier request (one per order; `return_requests.order_id` is unique).
- **Delivery date:** `orders.delivered_at` is set by `transitionTo()` when an order first becomes Delivered or Completed. It is never changed after that.
- **Flow:** `ReturnStatus` requested → approved → refunded, or rejected. `ReturnRequest::approve()` / `reject()` lock the row and only act on Requested. A rejection needs an admin note, which the customer sees. Customers pick which order items to return (`return_request_items`).
- **Photos:** stored on the **private `local` disk** under `returns/{id}/` (`ReturnRequestPhoto::DISK`). They are served only through `returns.photo`, to the owner or an admin; anyone else gets 404. Tests use `Storage::fake('local')` and `UploadedFile::fake()->create(..., 'image/jpeg')`.
- **Refunds:** `App\Services\Returns\RecordRefund` is the only place refunds are recorded.
  - **Amount:** greater than 0 and at most `Order::refundableAmount()`, which is `total - refunded_amount`.
  - **Restock:** pairs are restocked per item with `Product::returnToStock()`.
  - **Order update:** the refund adds to `orders.refunded_amount`. When the order is refunded in full, its status becomes **Refunded**. A partial refund keeps it Completed, and `statusLabel()` shows "Partially refunded".
- **HitPay refund API:** `HitPayClient::refund()` calls `POST /v1/refund` with JSON `{payment_id, amount}`, per docs.hitpayapp.com/apis/refund/refund. A response with status `succeeded` returns the refund `id`, which is stored as `refund_reference`. It's only used when `RecordRefund::canUseHitPay()` is true: an online order with a real `gateway_payment_id` (not simulated). Otherwise the admin records a **manual** refund with a reference. Some methods can't be refunded through HitPay (e.g. WeChat Pay MY); the API error is shown and the admin uses manual.
- **Never refund twice:**
  - `refund_attempted_at` is committed under a row lock before HitPay is called.
  - A 4xx response clears it, so the admin can retry freely.
  - A timeout or 5xx leaves it set; the admin must tick "I checked HitPay" (`retry_confirmed`) to try again.
  - The admin can also record the refund manually if the dashboard shows it went through.
- **Not verified live:** the sandbox has never taken a real payment, so the refund call has only been tested with `Http::fake()`.

## Admin dashboard, reports and notifications

- **One source of sales figures:** `App\Services\Reports\SalesReport` (for a `ReportPeriod`). The dashboard cards and chart, the reports page, the PDF and the CSV all use it, so they agree. Never sum orders elsewhere.
  - **What counts as revenue:** the `Order::revenue()` scope: `paid_at` set and status Paid / Shipped / Completed / Refunded. Online orders count once paid; COD orders only once the cash is collected (Completed). Cancelled, failed and unpaid orders never count.
  - **Dating:** by `paid_at`, in Malaysian days. Weeks start Monday.
  - **Net revenue:** order totals (including shipping) − `refunded_amount`. Refunds count against the order's payment date.
  - **Grouping:** done in PHP, not SQL, so Malaysia-time days work the same on MySQL and SQLite.
- **`ReportPeriod`:** day, week (Mon–Sun), month, or custom (at most 366 days counting both ends; longer than 62 days groups by month). Start and end are local times; convert with `->utc()` for queries. Labels: "Fri 6 Nov 2026", "2 Nov – 8 Nov 2026" (`rangeLabel()`; the year shows on both sides across a new year), "November 2026".
- **Report filter (`/admin/reports`):** one **Flatpickr** picker (npm package, bundled by Vite, no CDN) that changes with the Period select: Daily, Weekly (a click selects the Mon–Sun week, sent as its Monday), Monthly (`monthSelect` plugin), Custom (range, 2 months side by side, end at most 366 days after the start).
  - `resources/js/report-picker.js` is loaded only when `[data-report-picker]` is on the page (dynamic import, own chunk) with theme overrides in `resources/css/report-picker.css`. **Run `npm run build` after changing them.**
  - It only writes hidden `date` / `month` / `from` / `to` inputs of a plain GET form, and "Show report" is enabled once the range is complete. "Today" comes from the server (`data-today`, Malaysia time), not the browser clock.
  - Quick presets (Today, Yesterday, Last 7 days, This month, Last month) are plain links.
  - **Server:** `ReportController::report()` validates (format, from ≤ to, ≤ 366 days, **no future dates**; the current week/month is allowed "so far") and `queryFor()` gives the canonical query. The picker restores from it, and Print / PDF / CSV links use it, so every output covers the range on screen.
  - Tests: `SalesReportPeriodTest`.
- **Dashboard:**
  - **Cards:** today / this week / this month so far, each compared with the same span one period earlier.
  - **Chart:** a 30-day SVG bar chart (`<x-admin.bar-chart>`, server-rendered, no JS library, with a data table).
  - **Status counts:** one per status, plus "Partially refunded" and "Failed delivery".
  - **Top sellers:** top products, brands and categories over 30 days.
  - **Low stock:** a product that started with more than `shop.low_stock_threshold` (2) pairs and is now at or below it. Single pairs never count. The card also lists products still marked Available but with stock 0.
  - **Recent orders:** the latest few.
- **PDF export:** `barryvdh/laravel-dompdf` renders `admin/reports/pdf.blade.php`. That template uses plain inline CSS, because dompdf can't read Tailwind 4, and has no images, because there's no GD. The print view uses `print:hidden` on the admin sidebar, bell and filters.
- **CSV export:** sectioned (summary, over time, by product, by category, by payment method) with a UTF-8 BOM for Excel. Tests read it with `streamedContent()`.
- **Notifications:**
  - Laravel **database notifications** (`notifications` table), class `App\Notifications\AdminAlert` with data `{kind, title, body, url}`, sent only through `App\Services\AdminNotifier` to every admin.
  - `AdminNotifier` wraps sends in `DB::afterCommit()`, so it's safe to call inside transactions: a rollback never notifies.
  - **Triggers:**
    - `PlaceOrder`: a new order, and low stock when a product crosses the threshold;
    - `Order::markPaymentFailed()`: failed or expired payment;
    - `ReturnRequestController@store`: a return request;
    - `ContactController@store`: a customer message (not honeypot spam).
  - **Bell:** `<x-admin.notification-bell>` in the admin layout's top bar, a `<details>` dropdown. Opening a notification is a POST to `admin.notifications.open`, which marks it read and redirects to its url. Admins can only open their own; other ids return 404.

## Customer account area

- **Layout:** account pages use `<x-layouts.account>`, which adds a side menu (Profile, Addresses, My orders) inside the shop layout. `<x-avatar :user>` shows the photo or the user's initials.
- **Profile (`Account\ProfileController`):**
  - Customers can change their name and **account phone** (`users.phone`). Changing the phone clears `phone_verified_at` and cancels any pending code. The email can't be changed here.
  - **Avatar:** stored on the public disk under `avatars/{id}/`, max 2 MB, not resized (no GD). The old file is deleted only after the change is saved. `User::avatarUrl()` uses `asset()`, like product images.
  - **Password change:** needs the current password (`current_password`), uses the `password` error bag, replaces the remember token and regenerates the session.
- **Email verification:**
  - `User implements MustVerifyEmail`. Registering fires `Registered`, which makes Laravel send `VerifyEmail` (a link to the signed `verification.verify` route).
  - The routes are hand-written in `Auth\EmailVerificationController`; keep the names `verification.notice`, `verification.verify` and `verification.send`.
  - **Only checkout requires it** (`verified` middleware on `checkout.create` and `checkout.store`). The shop layout shows a "please verify" banner until it's done.
  - Factories create verified users; use `->unverified()` for tests that need otherwise.
- **Phone OTP is a DEMO:**
  - `App\Services\PhoneVerification` creates a 6-digit code, stored only as a hash in the cache, tied to the phone number it was sent for. It's valid 10 minutes (wrong guesses don't extend it), allows 5 attempts and one resend per minute.
  - **No SMS is sent, and no text may claim one was:**
    - Every code is written to the log as `[DEMO SMS, NOT SENT] Phone verification code for … : 123456, expires …`.
    - Only with `APP_ENV=local` is it also flashed as `demo_otp` and shown on the profile in a box labelled "Demo only (local)".
    - Tests read the code from the log (`MessageLogged` event).
  - It sets `users.phone_verified_at`; `User::hasVerifiedPhone()` shows the status on the profile and admin order page.
- **Address book:**
  - **Data:** the `addresses` table (`Address` model, `Account\AddressController`, `AddressRequest`), at most `shop.max_addresses` (10) per customer, Malaysian `shop.states`, and 5-digit postcodes. Addresses are always looked up through `$user->addresses()`, so another customer's address is 404.
  - **Default:** exactly one per customer. Change it only with `Address::makeDefault()` and `deleteKeepingDefault()`; `is_default` isn't fillable.
  - **Checkout:** posts `address_id`, either a saved id (`exists` scoped to the user) or `new` plus the manual fields from the `partials/address-fields` partial, with an optional `save_address`.
  - **Snapshot:** the order gets `Address::toShippingSnapshot()`, which fills `shipping_name`, `shipping_phone` and `shipping_address` (lines joined with `\n`). Orders never link to an address, so editing or deleting one leaves past orders unchanged.
  - Checkout no longer changes the account phone.
- `users.address` is a **legacy column, no longer used**. It was kept so no data was dropped.
- **Existing accounts:** migration `…000022` marks accounts created before 29 Oct 2026 (when verification arrived) as email-verified. Accounts created later still have to verify.
- **Seed data:** all seeded accounts have a verified email **and phone**, except `unverified@thriftstore.test`. Seeded customers have Malaysian default addresses (`customer@` has Home and Office). `unverified@thriftstore.test` / `password` is an account with an unverified email.

## Recommendations (rule-based, proposal objective 2 / module 5)

- **Where:** `App\Services\Recommendations\SimilarProducts::for($product)` powers "You may also like" on the product page. It also shows on **sold** pairs ("This pair is sold. You may also like").
- **Candidates:** available products only (`Product::available()`), excluding the current one, scored in one SQL query (`CASE WHEN` sums, so it works on MySQL and SQLite; `condition` is a reserved word, so columns are wrapped with the query grammar).
- **The IF-THEN rules and weights** (`SimilarProducts::WEIGHTS`):

  | Rule | Points |
  |---|---|
  | same UK size | +3 |
  | same category | +2 |
  | same brand (case-insensitive) | +2 |
  | price within ±30% (inclusive, rounded to sen) | +1 |
  | condition the same or one step away (Like new ↔ Good ↔ Fair) | +1 |

- **Selection and order:**
  - Products scoring 0 are excluded, and at most 8 (`LIMIT`) are shown.
  - Order: score descending, then closest price (`abs(price - ?)`), then newest, then id, so the result is **deterministic** and testable.
- **"Why" labels:** each result carries `recommendation_score` and `recommendation_reasons` ("Same size · Same brand"), which `<x-product-card :reasons>` shows.
- No machine learning, by design; keep it that way. Change weights only in `WEIGHTS` and update `RecommendationTest`.
- `SimilarProducts::for()` is still used as-is for the cart's "similar pairs" links. The product page goes through `Recommender` (below).

### Personal recommendations

- **Data:** `user_preferences` (one row per user: `shoe_size`, `preferred_brands` json, `min_price`, `max_price`), saved from the "Shoe preferences" card on the profile (`Account\PreferenceController`, error bag `preferences`; brands must be ones the shop has, at most 5). `user_interactions` (`view` / `cart` / `purchase`, optional `source`).
- **Logging:** only through `App\Services\Recommendations\Interactions`, never for guests, written after commit.
  - view: `ShopController::show`; a repeat view of the same product (same source) within 30 minutes isn't logged again;
  - cart: `CartController@store` after a successful add;
  - purchase: model events, not payment code. `OrderItem` created on a COD order, or `Order` status changing to Paid (online).
  - `?src=` on product links is stored as `source` only for `Interactions::SOURCES` (`recommendation`, `for_you`). `<x-product-card src="…">` adds it.
- **`PersonalScore` (0-100):** size 40 (saved size, else the most frequent interacted size; ties: higher weight, then smaller size), brand 20 (saved preferred brand = 20, else 20 x weight / highest), category 20 (20 x weight / highest), budget 20 (inside the saved budget, else within 30% of the average interacted price). Weights: view 1, cart 3, purchase 5. No data = 0 for that part. Only the user's own rows.
- **`Recommender::forProduct()`** ("You may also like"): similarity = `SimilarProducts` score / 9 x 100. With personal data, final = 0.6 x similarity + 0.4 x personal; guests and users without data get similarity only. Minimum 40, top 4, for everyone. Ties: smaller price difference, newest, id. Leaves out unavailable/sold, the viewed pair and pairs in the user's cart (active holds).
- **`Recommender::forYou()`** (home "For you", logged in only): personal score only, above 0, top 4, same exclusions. Without data it shows a link to set preferences.
- Tests: `PersonalRecommendationTest`. Change weights only in the class constants and update the tests.

## ML module (`ml-service/`, Python)

- **Scope:** admin-only features: revenue forecasting, restock prediction and price recommendation. It is **not** used by the customer recommendations, which stay rule-based (above). This goes beyond the proposal's module list; say so in the report.
- **Setup:** Python 3.14 venv at `ml-service/venv`, packages in `ml-service/requirements.txt` (pandas, scikit-learn, xgboost, lightgbm, statsmodels, prophet, shap, FastAPI, …). Prophet 1.5 installs and fits on Windows from its wheel (bundled CmdStan).
  ```bash
  py -3.14 -m venv ml-service/venv
  ml-service/venv/Scripts/python -m pip install -r ml-service/requirements.txt
  ml-service/venv/Scripts/python ml-service/test_connection.py   # counts + first/last order date
  ```
- **Final models** (`ml-service/models/*.joblib` + `.json` metadata), built in notebooks 02–05:
  - revenue: XGBoost + uplift on major events only (11.11, 12.12, Black Friday, pre-Raya, Raya closure), calibrated 80% intervals (`forecasting/`, notebook 03);
  - restock: Random forest per model × size band (S/M/L), plus a censored-demand correction for groups with < 2 pairs (`restock/`, notebook 04);
  - price: Random forest on price ÷ retail, trained on pairs sold within 45 days (before markdowns), with SHAP reasons that add up (`pricing/`, notebook 05).
- **ML API (FastAPI)**, what Laravel calls:
  - Start: `ml-service\run_api.bat` (= `uvicorn api.main:app --host 127.0.0.1 --port 8001` from `ml-service/`). It binds to **127.0.0.1 only** and needs MySQL running.
  - Every request needs the header `X-API-Key: <ML_API_KEY>`. The key is in `ml-service/.env` and the same value in the Laravel `.env` as `ML_API_KEY`, with `ML_API_URL=http://127.0.0.1:8001`. Both files are git-ignored; never commit the key. Missing or wrong key → 401.
  - Endpoints:
    - `GET /health`: model names, `trained_until`, key metric, cache ages, retrain status;
    - `GET /forecast/revenue?days=7` (1–30): forecast from tomorrow (Malaysia date) with lower/upper, event label and uplift, next-7-days total and range, last 30 days of actuals (today marked `partial_day`);
    - `GET /restock/report?as_of=YYYY-MM-DD&risk=HIGH,MEDIUM`: summary counts, total pairs suggested, every group with sizes to look for and `demand_source`, brand roll-up;
    - `POST /price/recommend`: brand and category must exist in the DB (case-insensitive), material must be in `config/shop.php` `materials`, condition `like_new|good|fair`, size 3.0–12.0 in 0.5 steps; `name` may be a new model (flagged `unseen_model`);
    - `POST /admin/retrain`: runs `retrain.py` in the background, reloads the models and clears the cache (409 if one is already running).
  - Forecast and restock report are cached in memory for 1 hour (cleared by a retrain or a restart).
  - Errors are JSON `{error, details}`: 422 for bad input (readable messages), 503 when a model file is missing or the database is down.
- **Retrain without the API:** `ml-service\venv\Scripts\python ml-service\retrain.py` refits all 3 models from the current database with the notebooks' final settings (about 45 s), writes files atomically and keeps the notebooks' evaluation figures in the metadata. Restart the API afterwards.
- **Tests:** `ml-service\venv\Scripts\python -m pytest ml-service\tests -v` (15 tests, TestClient). They use the saved models and the real MySQL database.

### ML admin pages (Laravel side)
- **Client:** `App\Services\Ml\MlService`, configured in `config/services.php` `ml` (`ML_API_URL`, `ML_API_KEY`, `ML_API_TIMEOUT` 5 s, connect 2 s, retrain 30 s).
  - It sends `X-API-Key` and **never throws**: every call returns an `App\Services\Ml\MlResult` (`ok()`, `get()`, `error`, `details`, `status`, `offline()`), and failures are logged without the key.
  - If the URL or key is empty it doesn't call at all and reports "not configured". `phpunit.xml` sets both empty, so tests must set `config('services.ml.*')` and use `Http::fake()` (see `MlInsightsTest`).
- **Admin sidebar "Insights"** (admin middleware, `InsightsController`):
  - **Revenue forecast** (`admin.insights.forecast`, `?days=7|30`): `<x-admin.forecast-chart>`, a server-rendered SVG with actual + forecast lines, a shaded 80% band and red event labels (11.11 …). Cards compare the next 7 days with the last 7 complete days; the table lists each day; "Model trained until".
  - **Restock** (`admin.insights.restock`, `?risk=HIGH|MEDIUM|LOW|OVERSTOCK`, filtered in PHP):
    - risk cards and total pairs to restock;
    - the group table, with sizes to look for and the demand source ("In-stock rate" has a tooltip);
    - Overstock suggests a 15% markdown (`OVERSTOCK_MARKDOWN_PERCENT`), and each group links to the Products list filtered by model + size band (`size_min`/`size_max` on `admin.products.index`; bands in `InsightsController::SIZE_BANDS`);
    - brand roll-up.
    - **Presentation only** (the ML output is unchanged): `InsightsController::parseSizes()` turns the API's "UK 7 x2 (28%), …" (most popular first) into chips **sorted by size**, with the share in each chip's `title`. Priority chips (2+ pairs, or the group's top share) are dark green.
    - Each group shows a band badge (`bandLabel()`: "Small · UK 3–5.5").
    - `?view=list` is the **Shopping list**: groups with restock > 0, per model, highest risk first, sizes grouped by band, with a checkbox per size (client-side only, not saved). Print uses `print:` classes (the sidebar, cards, filters and tables are hidden).
    - **Export CSV** is `admin.insights.restock.csv` (model, brand, size band, size, quantity, risk; one row per size). The risk filter applies to both views and the CSV.
  - **ML status** (`admin.insights.status`): API online/offline, each model's name, trained-until date and key metric, cache, last retrain. "Retrain now" posts `admin.insights.retrain` (confirm dialog, flash message; 409 = already running).
  - Every page renders when the API is down (`<x-admin.ml-offline>`).
- **Product form:**
  - It now has **Retail price (RM)** (`retail_price`, optional, validated in `ProductRequest`).
  - **Suggest price** posts the form values as JSON to `admin.products.suggest-price` (`PriceSuggestionController`), which maps `category_id` to the category name and calls the API.
  - The panel shows the price, the 80% range, the reasons that add up, warnings and notes. **Use this price** only fills the field; nothing is saved.
  - API validation errors come back as plain sentences ("Size (UK): must be …").
- **Dashboard:** 2 small cards ("Forecast next 7 days: RM X", "N groups need restock (HIGH)") or a muted "ML insights offline" note.
- **Weekly retrain:** `php artisan ml:retrain` (`App\Console\Commands\MlRetrain`) is scheduled `weeklyOn(0, '03:00')` in Malaysia time in `routes/console.php`. Locally the scheduler needs `php artisan schedule:work`.
- **Database:** `ml-service/db.py` `get_engine()` (SQLAlchemy + pymysql) reads `ml-service/.env`, which holds copies of Laravel's `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`. Update it when the Laravel DB settings change. `ml-service/venv` and `ml-service/.env` are in `.gitignore`; never commit credentials.
- **Times:** the database stores UTC. Convert to Malaysia time (+8 h) before grouping by day, as `SalesReport` does.
- **Training data:** `ThriftHistorySeeder` (semi-synthetic history, Nov 2024 – Nov 2026, CSVs in `database/seeders/data/thrift/`, source package in `thrift-ml-dataset/`). It **wipes** products, orders, carts, reviews, returns, interactions and notifications, and keeps only the test accounts in `KEEP_EMAILS`. Back up the database before running it. It added `products.retail_price` (nullable, fillable), used by price recommendation.

## Loyalty points

- **Rules:** all in `config/loyalty.php`.
  - Earn 1 point per RM1 of **item subtotal** (shipping excluded, rounded down), credited when the order becomes **Completed**.
  - Redeem in blocks of 100 points = RM5, from 100 points, at most 50% of the item subtotal.
  - Points expire 12 months after they were earned.
- **Ledger:** `point_transactions` (`PointTransaction`, type enum `PointTransactionType`: earn, redeem, reverse_earn, return_redeem, expire, adjust).
  - `points` is signed and `balance_after` is the running balance. Rows that add points are **lots** with `expires_at` and `remaining`.
  - Rows that remove points store which lots they used in `allocations`. Points are used and expire **oldest first (FIFO)**.
  - Invariant: a customer's latest `balance_after` = sum of `remaining` of their lots.
- **`App\Services\Loyalty\PointsService`** is the only writer.
  - Every write locks the customer's `users` row (`lockForUpdate`), expires due points, then changes the ledger, so the balance can't go negative or be spent twice. Concurrent redeems queue on the lock (only testable on MySQL).
  - `balance()` counts unexpired lots only, so expired points are never usable even before the job runs.
  - **Customer-facing values:** "worth RM X" uses `discountFor()` (whole blocks). Reports use `valueOf()` (exact liability).
- **Order flow hook:** `App\Observers\OrderPointsObserver` (registered in `AppServiceProvider`) runs on order `updated`, inside the callers' transactions, without changing any status logic:
  - → Completed: `earnFor` (once);
  - → Cancelled / Failed: return the redeemed points into their original lots (they keep their expiry);
  - → Refunded, or a partial refund on a completed order: take back earned points in proportion to `refunded_amount / total` (never below 0; any shortfall is noted).
- **Checkout:**
  - `orders.points_redeemed` and `orders.points_discount`. **`orders.total` is stored after the discount**, so HitPay's amount, the COD amount, refunds and SalesReport revenue all use the discounted total.
  - `$order->subtotal` adds the discount back (items total).
  - `PlaceOrder::handle(..., int $points)` checks the rules (`redeemError`) and calls `PointsService::redeem` inside its transaction, so any failure rolls the points back. A `PointsException` becomes a `points` validation error.
  - **The page's `expected_total` is the total before points**; the discount follows from the points chosen. A tiny inline `onchange` updates the summary.
  - Discount lines are shown in `<x-order-summary>`, the receipt and the admin order page.
- **Pages:**
  - Customer: **My points** (`account.points`, `/account/points`): balance, expiring in 30 days, history. It is only ever the signed-in user's own ledger. The product page shows "Earn X points".
  - Admin:
    - the order page has a "Loyalty points" panel with a link to **customer points** (`admin.customers.points`, `/admin/customers/{user}/points`): history plus a manual adjust form (`+`/`-` points, **reason required**, admin id stored in `admin_id`);
    - Reports shows "Points outstanding (now)".
- **Commands:**
  - `loyalty:expire` is scheduled daily at 00:30 Malaysia time.
  - `loyalty:backfill` gives points for existing Completed orders, dated by completion (COD: `paid_at`; online: `delivered_at`), with expiry and partial refunds in date order. It is idempotent.
  - On the dev DB: 2,177 orders, 351,345 points outstanding; `customer@thriftstore.test` has 4,370 points.
- **No notifications** are sent for points.
- **Tests:** `tests/Feature/Loyalty/PointsTest.php`.

## Thrift product details

- **Fields** (all optional; existing products show "Not specified"):
  - `material`: from `config('shop.materials')`
  - `insole_length_cm`: `decimal(4,1)`, 10–35, at most 1 decimal place
  - `sole_condition`: `SoleCondition` enum: excellent / good / fair / worn
  - `box_included`: boolean; the form posts a hidden `0` so unticking saves
  - `wear_notes`, `authenticity_notes`: ≤ 1000 characters each
- **Admin:** a "Thrift details" fieldset in `admin/products/_form`, validated in `ProductRequest`.
- **Product page:** shows them in the details list, with the wear notes highlighted in an amber "Wear & defects" box and the authenticity notes in their own box.
- **Shop filters:** `material` (only materials with available stock are offered; values outside the list are ignored) and `box=1` ("With original box"). Sole condition isn't a filter, because it overlaps the condition filter.

## Product reviews

- **Table and model:** `reviews` (`Review` model), holding user, product, order, `rating` **0.5–5.0 in half stars** (`decimal(2,1)`, cast `decimal:1`, so it reads as `"4.5"`), `comment` (10–1000 characters), `hidden_at`, `edited_at`, `flagged_at` and `flag_reasons` (json).
  - **One per product per order:** the unique `(order_id, product_id)` index enforces it, so a lost double-submit race shows a friendly message.
- **Who can review:** `Order::allowsReviews()` (paid online orders: Paid / Shipped / Completed with `paid_at`; COD once Delivered or Completed; never pending, cancelled, failed or refunded) plus `Order::reviewableItems()` (excludes pairs in a *refunded* return and products already reviewed for that order). The same rule is used on every page.
- **Writing and editing:** `ReviewController` + `ReviewRequest` + `App\Services\Reviews\SaveReview` (the only place reviews and media are saved).
  - **Routes:** write at `/account/orders/{n}/items/{item}/review`; edit and delete at `/account/reviews/{review}`. Only the owner can edit or delete; anyone else gets 404.
  - **Editing** sets `edited_at`, but only if the rating, comment or media changed. Deleting removes the files, and the customer may then review again.
- **Star picker:** `<x-review-star-picker>`, ten radios in **natural order 0.5 → 5.0** (left half and right half of each star).
  - **Highlighting:** plain CSS in `resources/css/app.css` (`.star-picker`, `:has(~ input:checked)`).
  - **Readout:** an `<output>` updated by one `oninput`.
  - **Never reverse the radios and rely on CSS to flip them.** That was the "only 1 star" bug: with stale `public/build` CSS the stars rendered backwards, and the right-most star submitted 1. `HalfStarTest` checks the order.
- **Showing ratings:**
  - `<x-star-rating :value [:count] [round]>` fills by width, so half stars show exactly; `round` draws averages to the nearest half (`Review::roundToHalf()`) while the label keeps the exact value.
  - **Averages:** `Product::withRatingSummary()` for product averages; `Review::storeSummary()` for the store average (footer composer, home badge, `/reviews`).
  - **Breakdown:** `Review::breakdown($query)` buckets 5★ = 5.0, 4★ = 4.0–4.5, …, 1★ = 0.5–1.5. It reads rows, not `pluck`, because float keys truncate.
- **Media:** the `review_media` table (`ReviewMedia` model), up to **5 photos** (JPG/PNG/WebP, 5 MB) and **1 video** (MP4/WebM, 30 MB, ≤ 30 s), on the public disk under `reviews/{id}/`.
  - **Video length:** `App\Services\Reviews\VideoDuration` reads it from the MP4 `mvhd` box or the WebM Info/Duration header (there's no ffmpeg). Too long → validation error. Unreadable → accepted but flagged (`video_length_unknown`). Some MP4s are detected as `application/mp4`, which is accepted together with the `.mp4` extension.
  - **Display:** `<x-review-media>` shows thumbnails with a **CSS `:target` lightbox** (no JS) and plays the video with `<video controls>`.
  - **Too-large uploads:** `PostTooLargeException` (above `post_max_size`, 40M on this XAMPP) redirects back with a friendly message (`bootstrap/app.php`). Recommended php.ini: `post_max_size=64M`, `upload_max_filesize=32M`.
- **Moderation:** `/admin/reviews`.
  - **Filters:** visible / hidden / **flagged**; star rows; search.
  - **Whole reviews:** hide/unhide or delete.
  - **Single photos/videos:** hide/unhide or delete. `ReviewMedia::hide()` **moves the file to the private `local` disk**, so public links stop working; unhide moves it back.
  - **Flags:** set by `Review::refreshFlag()` after each create or edit. Reasons: rating ≤ 2, has media, edited, unknown video length. "Mark as checked" clears them, and the sidebar shows the flagged count.
  - **Notification:** new reviews send a bell notification (kind `review`), marked "(flagged)" when flagged.
- **Condition question:** `condition_rating`, "Condition matched the description?".
  - **Values:** 1–5 whole numbers, labels in `Review::CONDITION_LABELS`. Required on new reviews and edits; nullable for older reviews, which averages ignore.
  - **Shown as:** "Condition accuracy x/5" on `/reviews`, in the brand/category block and the store line; "Condition matched: n/5" on each review.
- **Related reviews:** most pairs are one-of-a-kind, so a product page **without visible reviews of its own** shows "No reviews for this pair yet", then:
  - the store rating line (→ `/reviews`);
  - `<x-related-reviews>`: "What buyers say about <brand>" when **other** pairs of that brand (case-insensitive) have at least 3 public reviews, otherwise the **category**, otherwise nothing. It shows the average, count, condition accuracy and the latest 3 reviews, each linking to its product.
  - Products with their own reviews (sold ones too) show only those.
- **`App\Services\Reviews\ReviewStats`:** the single source for review aggregates and related reviews.
  - **Public reviews:** `publicReviews()` = visible reviews on products that **aren't hidden**. The store page, footer and home badge use it too.
  - **Caching:** each aggregate is one query, **cached 10 minutes**.
  - **Clearing the cache:** the `Review` model's `saved` and `deleted` events call `forgetFor()`, which forgets the store key and **bumps version numbers** for that review's brand and category. Those keys also include the viewed product's id, and the database cache has no tags.
  - **Privacy:** review lists load `user:id,name` only, so email, phone and address are never fetched for public pages. `RelatedReviewsTest` checks this and that the query count doesn't grow (no N+1).
- **Store page:** `/reviews` (`StoreReviewController`) shows every visible review newest first, with the store average, total and breakdown. It's linked from the footer ("Customer reviews" plus the rating line) and the home page badge.

## Orders and categories

- Order status rules live in `OrderStatus::allowedTransitions()` (generic) and `Order::allowedTransitionsFrom()` (per payment method): pending → paid → shipped → completed, and cancel only from pending or paid (COD differs, see above). Completed, cancelled, failed and refunded are final. Refunded is set only by `RecordRefund`, never by an admin status button. The admin order page shows one button per allowed transition; each posts `status` to the same PATCH route. Change status **only** through `Order::transitionTo()`. It enforces the rules (throws `DomainException`) and, on cancel, returns item quantities to stock and flips `sold` products to `available` (hidden products stay hidden). It does all of this inside a transaction that locks and re-reads the order, so stock is never returned twice.
- Admins can't create or delete orders (they come from checkout). Routes are `admin.orders.index/show` plus `PATCH admin/orders/{order}/status`.
- Slugs for products and categories come from the `HasUniqueSlug` trait (`Model::uniqueSlug($name, $ignoreId)`) and are regenerated only on rename. Deleting a category that has products is refused with a flash error (the FK restricts it too).

## Product images

- Files go on the `public` disk under `products/{product_id}/`; `public/storage` is linked via `php artisan storage:link`. Build URLs with `ProductImage->url`, which uses `asset()` so it ignores `APP_URL` and returns null when the file is missing ("No image" in the admin list, `<x-shoe-placeholder>` in the shop).
- **Seeded photos (no real ones exist yet):** the original seed paths never had files, and `ThriftHistorySeeder` re-used those paths, so every product showed "No image". The fix:
  - `scripts/make_demo_photos.py` (Pillow, run with `ml-service\venv\Scripts\python`) draws one labelled **"DEMO IMAGE"** per model + colour into `storage/app/demo-photos/` (git-ignored; re-run to recreate), named `{model}--{colour}.jpg`, e.g. `nike-dunk-low--green-white.jpg`.
  - `php artisan products:attach-photos [dir]` (`AttachProductPhotos`) links files by model + colour, falling back to `{model}.jpg`. It only touches products **without a photo file**. Available/hidden pairs get their own copy `products/{id}/attached-….jpg`; sold pairs share `products/library/{file}`. Run it after `ThriftHistorySeeder`.
  - **Real photos later:** put files with the same names in a folder and run `php artisan products:attach-photos <folder> --replace`. That replaces only photos the command attached; admin uploads are never touched.
  - Because files can be shared, removed images are deleted with `ProductImage::deleteUnusedFiles()`, which skips files another row still uses.
- Always call `Product::ensurePrimaryImage($preferredId)` after adding or removing images; it maintains the "exactly one primary" invariant.
- File/DB consistency: new uploads are stored inside the transaction and deleted if it fails; removed files are deleted only after the DB change commits.
- Deleting a product that has order items is refused with a flash error (and the `order_items` FK restricts it anyway). Hiding a product is the alternative.
- PHP here (XAMPP) has no GD extension, so tests must use `UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')`, not `fake()->image()`.

## Conventions

- **Dates and times:** stored and compared in **UTC** (`config('app.timezone')` stays `UTC`). They are displayed in `config('app.display_timezone')` (`APP_DISPLAY_TIMEZONE`, default `Asia/Kuala_Lumpur`). In views, receipts and emails, always format with the Carbon macros from `AppServiceProvider`: `->shopDate()` (26 Oct 2026), `->shopDateTime()` (26 Oct 2026, 3:45 pm) and `->shopTime()` (3:45 pm). Never use `->format()` for display. Order numbers use the Malaysian date. Future reports that group by day should group by Malaysian day.
- Blade gotcha: a template that uses inline `@php(...)` can't also contain an `@php … @endphp` block, because the compiler pairs them wrongly. Stick to one style per file.

- `tests/TestCase.php` calls `withoutVite()`, so feature tests don't need built assets. Running the app in a browser does: use `npm run dev` or `npm run build`. **`public/build` goes stale whenever views or CSS gain new classes**; without `public/hot` (the dev server) the browser uses that old CSS. Rebuild after pulling changes.
- Models use the Laravel 12 style: a `casts()` method instead of a `$casts` property, plus `HasFactory` with a `@use HasFactory<XFactory>` docblock (see `app/Models/User.php`).
- Factories live in `database/factories` and seeders in `database/seeders`. `DatabaseSeeder` is the entry point for `db:seed`.
