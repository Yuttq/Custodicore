<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\VisitorApiController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile app API
|--------------------------------------------------------------------------
| Every route/shape here matches src/services/api.js in the mobile repo
| exactly (same paths, same HTTP verbs) — that file was already written
| against this contract before this backend existed, so nothing on the
| mobile side needs to change except src/mock/devFlags.js (flip the
| USE_MOCK_* flags off) and EXPO_PUBLIC_API_URL (point it at this backend).
|
| Auth: Sanctum bearer tokens (Authorization: Bearer <token>), issued by
| POST /auth/login (verified email only) and the Google endpoints — NOT by
| /auth/register, which only sends a verification email. See config/auth.php's 'sanctum'
| guard and App\Models\Account (the accounts table backs both staff web
| logins AND mobile visitor logins).
|
| Naming note: the mobile app's own routes use "scheduleId" for what this
| schema calls visit_requests.visit_request_id (see the doc comment on
| VisitorApiController) — kept as-is here so the mobile code doesn't need
| to change.
*/

Route::prefix('auth')->group(function () {
    // Throttled against password guessing.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1,login');
    // Throttled: each call may fetch Google's signing keys.
    Route::post('/google', [AuthController::class, 'loginWithGoogle'])->middleware('throttle:10,1');
    // Also throttled against password guessing (a valid Google token is required too).
    Route::post('/google/link', [AuthController::class, 'linkGoogle'])->middleware('throttle:10,1');
    // Same limit: verifies a Google token and creates an account.
    Route::post('/google/register', [AuthController::class, 'registerWithGoogle'])->middleware('throttle:10,1');
    // Creates an account and sends a verification email; never signs in.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1,register');
    // Email verification (Phase 2). Neither issues a token. Resend is also
    // limited per email address inside the controller.
    Route::post('/email/verify', [EmailVerificationController::class, 'verifyApi'])->middleware('throttle:10,1,email-verify');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1,email-resend');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Public: the registration screen shows these BEFORE the visitor has an
// account. Same text as the web /terms and /privacy pages (config/legal.php).
Route::get('/legal', [LegalController::class, 'api']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me',[AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateMe']);

    Route::get('/documents', [VisitorApiController::class, 'documents']);
    Route::post('/documents', [VisitorApiController::class, 'storeDocument']);
    Route::post('/relationships/{relationship}/supporting-document', [VisitorApiController::class, 'storeSupportingDocument']);

    // Visit features need a staff-approved visitor (verification_status =
    // verified). Pending/rejected visitors can still use /me, /documents
    // and /notifications above and below. See EnsureVisitorApproved.
    Route::middleware('visitor.approved')->group(function () {
        Route::get('/visits', [VisitorApiController::class, 'index']);
        Route::get('/visits/upcoming', [VisitorApiController::class, 'upcoming']);
        Route::get('/visits/history', [VisitorApiController::class, 'history']);

        Route::post('/schedules/{visitRequest}/confirm', [VisitorApiController::class, 'confirm']);
        Route::post('/schedules/{visitRequest}/decline', [VisitorApiController::class, 'decline']);
        Route::get('/schedules/{visitRequest}/qr', [VisitorApiController::class, 'qr']);
        Route::get('/schedules/{visitRequest}/timeline', [VisitorApiController::class, 'timeline']);
    });

    Route::get('/notifications', [NotificationApiController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationApiController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [NotificationApiController::class, 'markRead']);
});
