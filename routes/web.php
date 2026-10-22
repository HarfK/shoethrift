<?php

use App\Http\Controllers\AccountOrderController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HitPayWebhookController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.show');

// Guests can use the cart; login is only required at checkout.
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/items', [CartController::class, 'store'])->name('cart.items.store');
Route::patch('/cart/items/{productId}', [CartController::class, 'update'])->whereNumber('productId')->name('cart.items.update');
Route::delete('/cart/items/{productId}', [CartController::class, 'destroy'])->whereNumber('productId')->name('cart.items.destroy');

Route::middleware('auth')->group(function () {
    Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout.create');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/checkout/complete/{orderNumber}', [CheckoutController::class, 'complete'])->name('checkout.complete');

    Route::get('/account/orders', [AccountOrderController::class, 'index'])->name('account.orders.index');
    Route::get('/account/orders/{orderNumber}', [AccountOrderController::class, 'show'])->name('account.orders.show');
    Route::post('/account/orders/{orderNumber}/pay', [AccountOrderController::class, 'pay'])->name('account.orders.pay');
    Route::post('/account/orders/{orderNumber}/check', [AccountOrderController::class, 'check'])->name('account.orders.check');
    Route::get('/account/orders/{orderNumber}/receipt', [AccountOrderController::class, 'receipt'])->name('account.orders.receipt');
    // Local test tool; the controller returns 404 unless APP_ENV=local.
    Route::post('/account/orders/{orderNumber}/simulate-payment', [AccountOrderController::class, 'simulatePayment'])->name('account.orders.simulate');
});

// Called by HitPay, not a browser: no session auth, CSRF-exempt (see bootstrap/app.php), signature-verified.
Route::post('/webhooks/hitpay', HitPayWebhookController::class)->name('webhooks.hitpay');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    // The name `password.reset` is what Laravel's reset-link email points to.
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::resource('products', ProductController::class)->except('show');
    Route::resource('categories', CategoryController::class)->except('show');
    // Orders come from checkout and are never deleted, so admins can only view them and change status.
    Route::resource('orders', OrderController::class)->only(['index', 'show']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
});
