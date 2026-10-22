# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**ShoeThrift** is a final-year project: a web-based thrift store for second-hand **shoes** (not clothing) in Malaysia, with a rule-based personalised recommendation engine. Prices are in RM, and FPX payment is planned. Recommendations must be deterministic rules: show available products with the **same UK size / same category / same brand** as the viewed product. No machine learning. Keep the code consistent with the proposal's modules (auth, profile, admin products, browse/search, recommendations, cart, checkout, order tracking, admin reports) and flag any divergence.

A thrift store system built on Laravel 12 (PHP ^8.2). So far the app has the database layer, hand-written session auth, an admin area for products, categories and orders (`/admin/...`), and the public shop: home (`/`), shop listing (`/shop`) and product page (`/shop/{slug}`). It also has a cart (`/cart`), checkout (`/checkout`, orders are placed as Pending) and "My orders" (`/account/orders`). FPX payment, rule-based recommendations, customer-side cancel and admin reports don't exist yet. The frontend tooling is Vite 7 + Tailwind CSS 4 via `laravel-vite-plugin` (`resources/css/app.css`, `resources/js/app.js`).

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
- Seed data: `admin@thriftstore.test` / `password` (admin) and `customer@thriftstore.test` / `password`. `OrderSeeder` decrements stock and marks products sold for non-cancelled orders.

## Auth and admin area

- Auth is hand-written: no Breeze, Fortify or Jetstream, and no Alpine/React/Vue. Views are Blade + Tailwind 4. The controllers are in `app/Http/Controllers/Auth`, and login validation plus rate limiting (5 attempts per email+IP) is in `app/Http/Requests/Auth/LoginRequest.php`.
- `LoginController::homeFor($user)` is the single source of truth for where a user lands: `/admin` for admins, `/` (`home`) for customers. It's used after login, and through `redirectUsersTo()` in `bootstrap/app.php` for signed-in users hitting guest-only pages. Logout redirects to `/login`, per the proposal's test plan.
- Admin routes go in the `auth` + `admin` route group in `routes/web.php`, with names prefixed `admin.`. The `admin` middleware alias (`EnsureUserIsAdmin`, registered in `bootstrap/app.php`) returns 403 for non-admins. Sidebar links are defined at the top of `resources/views/components/layouts/admin.blade.php`.
- Pages use anonymous component layouts: `<x-layouts.guest>` (auth card) and `<x-layouts.admin>` (sidebar). Forms use `<x-form.field>` (label + input + error) and `<x-button>`. The `<head>` is shared via `partials/head`.
- `MAIL_MAILER=log`: password-reset emails are written to `storage/logs/laravel.log`. The reset route must stay named `password.reset`, because Laravel's reset notification links to it.
- Admin CRUD pattern (see `Admin/ProductController`): a resource controller; Form Requests in `app/Http/Requests/Admin` with shared rules in an abstract base (`ProductRequest`); a shared `_form` partial for create/edit; `<x-form.select>` for enum and relation dropdowns; `back()->with('success'|'error', ...)` for flash banners, which the admin layout renders. Enums expose `label()` for display.

## Public shop

- Public pages use `<x-layouts.shop>` (header with search, cart icon and account `<details>` menu; footer). Its footer categories come from a view composer in `AppServiceProvider`. Products render with `<x-product-card>`, and `<x-shoe-placeholder>` is shown when there is no photo.
- Visibility rules: listings use `Product::available()` (status available and stock > 0). The product page uses `Product::visible()`, so sold items stay reachable with a "Sold" label and hidden ones 404. On a loaded model, use `$product->isAvailable()`.
- `ShopController::filters()` parses the query string defensively (invalid or array values are ignored, never 500). Category filters by slug; size compares numerically against `decimal(3,1)`; use `Product::formatSize()` for size strings. Filter dropdowns only offer values that have available stock.
- The interactive bits work without JS: mobile menu/account menu are `<details>`, the mobile filter panel is a checkbox + `peer-checked`, the gallery is a CSS scroll-snap strip with `#photo-N` anchors. The only JS is `onchange` submit on the sort select (with a `<noscript>` button).
- "Log in to check out" (cart page) links to `/login?return=/cart`. `LoginController::create` stores it as the intended URL, but only for same-site relative paths (no `//`, no backslashes), to prevent open redirects.
- Headless Chrome can't render below ~500px wide. To screenshot phone widths, load the page in a 390px `<iframe>` inside a local HTML file.

## Cart

- Always go through `App\Services\ShoppingCart`, never the `Cart`/`CartItem` models or the session directly. It stores guest carts in the session (`cart` key: product id => qty) and logged-in carts in `carts`/`cart_items` (matching the proposal ERD), and it caps quantities at stock.
- The `MergeGuestCart` listener (auto-discovered, on `Login`, which also fires on registration) merges the session cart into the saved cart: a product in both keeps the larger quantity, capped at stock, and unavailable items are dropped. Logout keeps the saved cart.
- `reconcile()` removes unavailable items and clamps quantities. It runs in the shop layout's view composer on every shop page, and the layout shows the resulting notices via `pullNotices()`. `ShoppingCart` is bound `scoped` and notices are *pulled* (cleared) so each shows once, even when the instance outlives a request (tests, Octane).
- The cart doesn't reserve stock; checkout must re-check availability and decrement stock atomically. Cart routes take `{productId}` (not model binding) so removing a since-deleted product still works. Product deletion cascades to `cart_items`.

## Checkout

- `App\Services\Checkout\PlaceOrder` is the only way orders are created from a cart. In one transaction it locks the cart's products (`lockForUpdate`, ordered by id to avoid deadlocks), re-checks availability and stock, prices everything from the locked rows in integer sen, creates the order and items, reduces stock (0 → `sold`), and throws `ItemsUnavailableException` / `PriceChangedException` (which roll everything back).
- The browser's `expected_total` is only **compared** with the server total and never used. Totals are `subtotal + shipping_fee`. The flat fee lives in `config/shop.php` and is stored on each order with `shipping_name`/`shipping_phone`; the `$order->subtotal` accessor derives the items total.
- The real concurrency behaviour (a second buyer blocks on the row lock, then is rejected) can't be tested on in-memory SQLite. It was verified manually on MySQL with two parallel PHP processes.
- Customers only reach orders through `$request->user()->orders()`, so another customer's order number 404s. Order numbers come from `Order::generateOrderNumber()` (`TS-YYYYMMDD-XXXXXX`, no ambiguous characters).
- Phone validation: `CheckoutController::PHONE_PATTERN` (Malaysian formats). `UserFactory`/`OrderFactory` generate matching numbers.

## Orders and categories

- Order status rules live in `OrderStatus::allowedTransitions()`: pending → paid → shipped → completed, and cancel only from pending or paid. Completed and cancelled are final. Change status **only** through `Order::transitionTo()`. It enforces the rules (throws `DomainException`) and, on cancel, returns item quantities to stock and flips `sold` products to `available` (hidden products stay hidden). It does all of this inside a transaction that locks and re-reads the order, so stock is never returned twice.
- Admins can't create or delete orders (they come from checkout). Routes are `admin.orders.index/show` plus `PATCH admin/orders/{order}/status`.
- Slugs for products and categories come from the `HasUniqueSlug` trait (`Model::uniqueSlug($name, $ignoreId)`) and are regenerated only on rename. Deleting a category that has products is refused with a flash error (the FK restricts it too).

## Product images

- Files go on the `public` disk under `products/{product_id}/`; `public/storage` is linked via `php artisan storage:link`. Build URLs with `ProductImage->url`, which uses `asset()` so it ignores `APP_URL` and returns null when the file is missing (the seeded image paths are placeholders with no files).
- Always call `Product::ensurePrimaryImage($preferredId)` after adding or removing images; it maintains the "exactly one primary" invariant.
- File/DB consistency: new uploads are stored inside the transaction and deleted if it fails; removed files are deleted only after the DB change commits.
- Deleting a product that has order items is refused with a flash error (and the `order_items` FK restricts it anyway). Hiding a product is the alternative.
- PHP here (XAMPP) has no GD extension, so tests must use `UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')`, not `fake()->image()`.

## Conventions

- `tests/TestCase.php` calls `withoutVite()`, so feature tests don't need built assets. Running the app in a browser does: use `npm run dev` or `npm run build`.
- Models use the Laravel 12 style: a `casts()` method instead of a `$casts` property, plus `HasFactory` with a `@use HasFactory<XFactory>` docblock (see `app/Models/User.php`).
- Factories live in `database/factories` and seeders in `database/seeders`. `DatabaseSeeder` is the entry point for `db:seed`.
