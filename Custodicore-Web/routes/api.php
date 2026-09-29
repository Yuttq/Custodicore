<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\VisitorApiController;
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
| POST /auth/login and /auth/register. See config/auth.php's 'sanctum'
| guard and App\Models\Account (the accounts table backs both staff web
| logins AND mobile visitor logins).
|
| Naming note: the mobile app's own routes use "scheduleId" for what this
| schema calls visit_requests.visit_request_id (see the doc comment on
| VisitorApiController) — kept as-is here so the mobile code doesn't need
| to change.
*/

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/google', [AuthController::class, 'loginWithGoogle']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/documents', [VisitorApiController::class, 'storeDocument']);

    Route::get('/visits/upcoming', [VisitorApiController::class, 'upcoming']);
    Route::get('/visits/history', [VisitorApiController::class, 'history']);

    Route::post('/schedules/{visitRequest}/confirm', [VisitorApiController::class, 'confirm']);
    Route::post('/schedules/{visitRequest}/decline', [VisitorApiController::class, 'decline']);
    Route::get('/schedules/{visitRequest}/qr', [VisitorApiController::class, 'qr']);
    Route::get('/schedules/{visitRequest}/timeline', [VisitorApiController::class, 'timeline']);

    Route::get('/notifications', [NotificationApiController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationApiController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [NotificationApiController::class, 'markRead']);
});
