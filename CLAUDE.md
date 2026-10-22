# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

A thrift store system built on Laravel 12 (PHP ^8.2). So far the app has the database layer and hand-written session auth with an admin area. There are no storefront or admin CRUD pages yet. The frontend tooling is Vite 7 + Tailwind CSS 4 via `laravel-vite-plugin` (`resources/css/app.css`, `resources/js/app.js`).

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
- Each product should have exactly one `is_primary` image (`Product::primaryImage()`). Use the `Product::available()` scope for buyable items (available and in stock) and `visible()` for anything not hidden.
- Seed data: `admin@thriftstore.test` / `password` (admin) and `customer@thriftstore.test` / `password`. `OrderSeeder` decrements stock and marks products sold for non-cancelled orders.

## Auth and admin area

- Auth is hand-written: no Breeze, Fortify or Jetstream, and no Alpine/React/Vue. Views are Blade + Tailwind 4. The controllers are in `app/Http/Controllers/Auth`, and login validation plus rate limiting (5 attempts per email+IP) is in `app/Http/Requests/Auth/LoginRequest.php`.
- `LoginController::homeFor($user)` is the single source of truth for where a user lands: `/admin` for admins, `/` (`home`) for customers. It's used after login, and through `redirectUsersTo()` in `bootstrap/app.php` for signed-in users hitting guest-only pages.
- Admin routes go in the `auth` + `admin` route group in `routes/web.php`, with names prefixed `admin.`. The `admin` middleware alias (`EnsureUserIsAdmin`, registered in `bootstrap/app.php`) returns 403 for non-admins. Sidebar links are defined at the top of `resources/views/components/layouts/admin.blade.php`, where Products, Categories and Orders are still `#` placeholders.
- Pages use anonymous component layouts: `<x-layouts.guest>` (auth card) and `<x-layouts.admin>` (sidebar). Forms use `<x-form.field>` (label + input + error) and `<x-button>`. The `<head>` is shared via `partials/head`.
- `MAIL_MAILER=log`: password-reset emails are written to `storage/logs/laravel.log`. The reset route must stay named `password.reset`, because Laravel's reset notification links to it.
- `tests/TestCase.php` calls `withoutVite()`, so feature tests don't need built assets. Running the app in a browser does: use `npm run dev` or `npm run build`.

## Conventions

- Models use the Laravel 12 style: a `casts()` method instead of a `$casts` property, plus `HasFactory` with a `@use HasFactory<XFactory>` docblock (see `app/Models/User.php`).
- Factories live in `database/factories` and seeders in `database/seeders`. `DatabaseSeeder` is the entry point for `db:seed`.
