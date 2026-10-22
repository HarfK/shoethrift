# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

A thrift store system built on Laravel 12 (PHP ^8.2). The repo is currently close to the stock Laravel skeleton: the work starts at the database layer (migrations, models, factories, seeders), and the frontend is intentionally deferred. The frontend tooling is Vite 7 + Tailwind CSS 4 via `laravel-vite-plugin` (`resources/css/app.css`, `resources/js/app.js`).

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

## Conventions

- Models use the Laravel 12 style: a `casts()` method instead of a `$casts` property, plus `HasFactory` with a `@use HasFactory<XFactory>` docblock (see `app/Models/User.php`).
- Factories live in `database/factories` and seeders in `database/seeders`. `DatabaseSeeder` is the entry point for `db:seed`.
