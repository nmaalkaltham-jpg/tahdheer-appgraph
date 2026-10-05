<?php

// مسارات نطاق reviews — يملكها بنّاء reviews حصريًا. تُضمَّن من api.php.
// Reviews domain routes (MIP §4.7 / §5.6) — owned exclusively by the reviews builder.

use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ReviewVoteController;
use Illuminate\Support\Facades\Route;

// Public read: approved reviews only. Same budget as the rest of the catalog.
Route::middleware('throttle:read')->group(function () {
    Route::get('/products/{productId}/reviews', [ReviewController::class, 'index'])
        ->whereNumber('productId');
});

// Writes are identified by X-Order-Token (see App\Domain\Reviews\ReviewerIdentity)
// and throttled far tighter than reads — a review is a rare, deliberate act.
Route::middleware('throttle:write')->group(function () {
    Route::post('/products/{productId}/reviews', [ReviewController::class, 'store'])
        ->whereNumber('productId');

    Route::post('/reviews/{reviewId}/votes', [ReviewVoteController::class, 'store'])
        ->whereNumber('reviewId');
});

// ---------------------------------------------------------------------------
// MODERATION — NOW ROUTED, under admin authorization, in routes/api-admin.php:
//   GET  /admin/reviews                  → queue + counts
//   POST /admin/reviews/{id}/approve|reject|reply
// gated by `reviews.moderate` (§8.4). The note below is kept because it states
// the condition that had to be met first, and it was: admin authorization exists now.
//
// WAS: DELIBERATELY NOT ROUTED.
//
// approve / reject / reply exist as methods on App\Domain\Reviews\ReviewService
// and are covered by tests. They are not exposed over HTTP because no admin
// admin authorization exists yet, and inventing an auth scheme here would be worse than
// having no endpoint: an unauthorized route could publish or delete public
// content anonymously.
//
// TODO(admin builder): once the admin authorization middleware lands, add under it —
//   POST /admin/reviews/{id}/approve  → ReviewService::approve
//   POST /admin/reviews/{id}/reject   → ReviewService::reject
//   POST /admin/reviews/{id}/reply    → ReviewService::reply
// gated by the `reviews.moderate` permission (§8.4). Until then moderation
// runs through the domain service (tinker / queue worker / admin module).
// ---------------------------------------------------------------------------
