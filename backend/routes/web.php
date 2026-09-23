<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CheckinController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PdlController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Administrator / Warden dashboard
|--------------------------------------------------------------------------
| PDL Management, Visitor Management, Visit Scheduling and Check-In /
| Check-Out are all read-only here — that data comes from intake, the
| mobile app and the gate's QR scanner, not this admin dashboard. The
| admin's only write access is registering a BJMP officer account and
| updating one on request (User Management). Everything still lives in
| the session, not a real database yet — see CustodiCore Database Design
| (project doc) and database/migrations for the schema this will run on
| once wired up.
*/

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('/users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');

    Route::get('/pdls', [PdlController::class, 'index'])->name('pdls.index');

    Route::get('/visitors', [VisitorController::class, 'index'])->name('visitors.index');

    Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');

    Route::get('/checkins', [CheckinController::class, 'index'])->name('checkins.index');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
});
