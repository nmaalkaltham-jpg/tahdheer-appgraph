<?php

// مسارات نطاق التجارة (سلة/دفع/طلبات) — يملكها بنّاء التجارة حصريًا. تُضمَّن من api.php.
// Commerce domain routes — owned exclusively by the commerce builder.

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

// طرق الدفع المقبولة — قراءةٌ عامّة رخيصة بلا جلسة.
Route::get('/checkout/methods', [CheckoutController::class, 'methods'])->middleware('throttle:read');

/*
 * قراءتان تتكرّران في كلّ صفحة: الترويسة تسأل عن السلّة عند كلّ تصيير، وصفحة
 * الطلب تستطلع حالته. حدُّ الكتابة (٦٠/دقيقة) كان يردّ ٤٢٩ على زائرٍ عاديّ،
 * فهما على حدّ القراءة — ولا تُغيّران حالةً فلا تحتاجان حمايته.
 */
Route::middleware('throttle:read')->group(function () {
    Route::get('/cart', [CartController::class, 'show']);
    Route::get('/orders/{token}', [OrderController::class, 'show']);
});

Route::middleware('throttle:write')->group(function () {
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::delete('/cart/items/{itemId}', [CartController::class, 'removeItem'])->whereNumber('itemId');
    Route::post('/cart/coupon', [CartController::class, 'applyCoupon']);
    Route::delete('/cart/coupon', [CartController::class, 'removeCoupon']);

    Route::post('/checkout/orders', [CheckoutController::class, 'store']);
    // §٢٢ — استعادةُ رابط الدفع لطلبٍ بانتظاره: نيّةٌ قائمة تُعاد، أو تُنشأ بمفتاحها.
    Route::post('/checkout/orders/{token}/payment', [CheckoutController::class, 'payment']);

    Route::post('/downloads/{entitlementId}/grant', [DownloadController::class, 'grant'])->whereNumber('entitlementId');
    Route::get('/downloads/redeem/{rawToken}', [DownloadController::class, 'redeem']);
});

// Provider webhooks are retried by the gateway — never throttled.
Route::post('/payments/webhook/{provider}', [PaymentController::class, 'webhook'])->whereAlpha('provider');

// DEV-ONLY (aborts 404 outside local/testing inside the controller).
Route::post('/payments/fake/{orderToken}/confirm', [PaymentController::class, 'fakeConfirm']);
