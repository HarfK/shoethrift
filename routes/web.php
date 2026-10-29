<?php

use App\Http\Controllers\Account\AddressController;
use App\Http\Controllers\Account\PhoneVerificationController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\AccountOrderController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\ReturnRequestController as AdminReturnRequestController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HitPayWebhookController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReturnRequestController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StoreReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.show');
Route::get('/reviews', StoreReviewController::class)->name('reviews.index');

// Content pages (footer). Policy numbers come from config/shop.php so pages and code agree.
Route::view('/returns-refunds', 'pages.returns')->name('pages.returns');
Route::view('/shipping', 'pages.shipping')->name('pages.shipping');
Route::view('/faq', 'pages.faq')->name('pages.faq');
Route::view('/terms', 'pages.terms')->name('pages.terms');
Route::view('/privacy', 'pages.privacy')->name('pages.privacy');
Route::get('/customer-service', [ContactController::class, 'create'])->name('pages.customer-service');
Route::post('/customer-service', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('pages.customer-service.store');

// The cart needs login: adding reserves the pair (timed hold, see App\Services\Cart\Reservations).
// Guests browse freely and are sent to login and back.
Route::middleware('auth')->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart/items', [CartController::class, 'store'])->middleware('throttle:cart-add')->name('cart.items.store');
    Route::patch('/cart/items/{productId}', [CartController::class, 'update'])->whereNumber('productId')->name('cart.items.update');
    Route::delete('/cart/items/{productId}', [CartController::class, 'destroy'])->whereNumber('productId')->name('cart.items.destroy');
    Route::post('/shop/{slug}/notify', [CartController::class, 'watch'])->name('shop.watch');
});

Route::middleware('auth')->group(function () {
    // Buying needs a verified email (Laravel's built-in flow; see EmailVerificationController).
    Route::middleware('verified')->group(function () {
        Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout.create');
        Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    });
    Route::get('/checkout/complete/{orderNumber}', [CheckoutController::class, 'complete'])->name('checkout.complete');

    // Email verification. The names are the ones Laravel's VerifyEmail notification and `verified` middleware use.
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');

    // Account area: profile, (demo) phone verification, address book.
    Route::redirect('/account', '/account/profile')->name('account');
    Route::get('/account/profile', [ProfileController::class, 'edit'])->name('account.profile');
    Route::patch('/account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::put('/account/password', [ProfileController::class, 'updatePassword'])->name('account.password.update');
    Route::post('/account/phone/code', [PhoneVerificationController::class, 'send'])->name('account.phone.send');
    Route::post('/account/phone/verify', [PhoneVerificationController::class, 'verify'])->name('account.phone.verify');

    Route::get('/account/addresses', [AddressController::class, 'index'])->name('account.addresses.index');
    Route::get('/account/addresses/create', [AddressController::class, 'create'])->name('account.addresses.create');
    Route::post('/account/addresses', [AddressController::class, 'store'])->name('account.addresses.store');
    Route::get('/account/addresses/{address}/edit', [AddressController::class, 'edit'])->whereNumber('address')->name('account.addresses.edit');
    Route::put('/account/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address')->name('account.addresses.update');
    Route::delete('/account/addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address')->name('account.addresses.destroy');
    Route::patch('/account/addresses/{address}/default', [AddressController::class, 'makeDefault'])->whereNumber('address')->name('account.addresses.default');

    Route::get('/account/orders', [AccountOrderController::class, 'index'])->name('account.orders.index');
    Route::get('/account/orders/{orderNumber}', [AccountOrderController::class, 'show'])->name('account.orders.show');
    Route::post('/account/orders/{orderNumber}/pay', [AccountOrderController::class, 'pay'])->name('account.orders.pay');
    Route::post('/account/orders/{orderNumber}/check', [AccountOrderController::class, 'check'])->name('account.orders.check');
    Route::get('/account/orders/{orderNumber}/receipt', [AccountOrderController::class, 'receipt'])->name('account.orders.receipt');
    // Local test tool; the controller returns 404 unless APP_ENV=local.
    Route::post('/account/orders/{orderNumber}/simulate-payment', [AccountOrderController::class, 'simulatePayment'])->name('account.orders.simulate');

    Route::get('/account/orders/{orderNumber}/return', [ReturnRequestController::class, 'create'])->name('account.returns.create');
    Route::post('/account/orders/{orderNumber}/return', [ReturnRequestController::class, 'store'])->name('account.returns.store');
    Route::get('/account/orders/{orderNumber}/items/{item}/review', [ReviewController::class, 'create'])->whereNumber('item')->name('account.reviews.create');
    Route::post('/account/orders/{orderNumber}/items/{item}/review', [ReviewController::class, 'store'])->whereNumber('item')->name('account.reviews.store');
    Route::get('/account/reviews/{review}/edit', [ReviewController::class, 'edit'])->name('account.reviews.edit');
    Route::put('/account/reviews/{review}', [ReviewController::class, 'update'])->name('account.reviews.update');
    Route::delete('/account/reviews/{review}', [ReviewController::class, 'destroy'])->name('account.reviews.destroy');

    // Private files: only the customer who sent the photo, or an admin.
    Route::get('/return-photos/{photo}', [ReturnRequestController::class, 'photo'])->name('returns.photo');
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

    Route::get('returns', [AdminReturnRequestController::class, 'index'])->name('returns.index');
    Route::get('returns/{return}', [AdminReturnRequestController::class, 'show'])->name('returns.show');
    Route::patch('returns/{return}/decision', [AdminReturnRequestController::class, 'decide'])->name('returns.decide');
    Route::post('returns/{return}/refund', [AdminReturnRequestController::class, 'refund'])->name('returns.refund');

    Route::get('messages', [ContactMessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{message}', [ContactMessageController::class, 'show'])->name('messages.show');
    Route::patch('messages/{message}/resolve', [ContactMessageController::class, 'resolve'])->name('messages.resolve');

    Route::get('reservations', ReservationController::class)->name('reservations.index');

    Route::get('reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
    Route::patch('reviews/{review}/visibility', [AdminReviewController::class, 'toggle'])->name('reviews.toggle');
    Route::delete('reviews/{review}', [AdminReviewController::class, 'destroy'])->name('reviews.destroy');
    Route::patch('reviews/{review}/checked', [AdminReviewController::class, 'markChecked'])->name('reviews.checked');
    Route::patch('review-media/{media}/visibility', [AdminReviewController::class, 'toggleMedia'])->name('review-media.toggle');
    Route::delete('review-media/{media}', [AdminReviewController::class, 'destroyMedia'])->name('review-media.destroy');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
    Route::get('reports/csv', [ReportController::class, 'csv'])->name('reports.csv');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    // POST: opening marks the notification read, so it mustn't happen from a stray link or prefetch.
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
});
