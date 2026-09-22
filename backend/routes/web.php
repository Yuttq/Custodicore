<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CheckinController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EligibilityController;
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
| Every route below currently renders from sample data in its controller —
| nothing here queries the database yet. See CustodiCore Database Design
| (project doc) and database/migrations for the schema this will run on
| once wired up.
*/

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/pdls', [PdlController::class, 'index'])->name('pdls.index');
    Route::get('/visitors', [VisitorController::class, 'index'])->name('visitors.index');
    Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::get('/checkins', [CheckinController::class, 'index'])->name('checkins.index');
    Route::get('/eligibility', [EligibilityController::class, 'index'])->name('eligibility.index');
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
});
