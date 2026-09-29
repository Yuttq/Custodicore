<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CheckinController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PdlController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitorController;

use App\Http\Controllers\Auth\LoginController;

use App\Http\Controllers\CustodyHistoryController;
use App\Http\Controllers\VisitationTrackingController;
use App\Http\Controllers\EligibilityController;

use App\Http\Controllers\FrontDesk\DashboardController as FrontDeskDashboardController;
use App\Http\Controllers\FrontDesk\CheckinCheckoutController;
use App\Http\Controllers\FrontDesk\ScheduleController as FrontDeskScheduleController;
use App\Http\Controllers\FrontDesk\VisitorLookupController;
use App\Http\Controllers\FrontDesk\SettingsController as FrontDeskSettingsController;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
| Session login shared by the three staff dashboards below (Warden/Admin,
| Record Officer, Front Desk Officer) — see App\Http\Controllers\Auth\
| LoginController. Visitor accounts are mobile-app only (Sanctum, see
| routes/api.php) and are refused here even though they share the same
| `accounts` table.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// The "home" page: sends a logged-in officer straight to their own
// dashboard, and anyone else to sign in first.
Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return match (auth()->user()->role?->role_name) {
        'System Administrator/Warden' => redirect()->route('admin.dashboard'),
        'Record Officer' => redirect()->route('dashboard'),
        'Front Desk Officer' => redirect()->route('frontdesk.dashboard'),
        default => redirect()->route('login')->with('status', 'This account type has no web dashboard — use the mobile app instead.'),
    };
})->name('home');

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

Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:System Administrator/Warden'])->group(function () {
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

/*
|--------------------------------------------------------------------------
| Records Officer module
|--------------------------------------------------------------------------
| Dashboard, PDL Management, Visitor Management, Custody History, and
| Visitation Tracking — the actual read/write CRUD side backed by the real
| database, as opposed to the Admin module above (read-only, session-based).
|
| NOTE: DashboardController, PdlController, and VisitorController below are
| NOT imported with `use` statements at the top of this file — this file
| already imports App\Http\Controllers\Admin\DashboardController,
| App\Http\Controllers\Admin\PdlController, and
| App\Http\Controllers\Admin\VisitorController for the Warden's admin
| module above. Importing a second class with the same short name would be
| a PHP error, so these three are referenced with their full namespaced
| path directly in each route instead. CustodyHistoryController and
| VisitationTrackingController don't collide with anything, so those two
| get normal `use` imports up top as usual.
|
| NOTE ON VIEWS: these controllers render view('dashboard'), view('pdl.*'),
| view('visitor.*'), view('eligibility.index'), view('custody-history') and
| view('visitation-tracking') — none of those Blade templates exist yet
| under resources/views (only admin/* and frontdesk/* do). That's a
| pre-existing gap from before this pass, not something login/auth touches
| — logging in as a Record Officer will hit a "view not found" error until
| those templates are built.
*/

Route::middleware(['auth', 'role:Record Officer'])->group(function () {

Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->name('dashboard');

Route::get('/custody-history', [CustodyHistoryController::class, 'index'])
    ->name('custody-history.index');

Route::get('/visitation-tracking', [VisitationTrackingController::class, 'index'])
    ->name('visitation-tracking.index');

Route::get('/visitation-tracking/{pdl}', [VisitationTrackingController::class, 'show'])
    ->name('visitation-tracking.show');


// --- PDL Management ---

Route::get('/pdls', [\App\Http\Controllers\PdlController::class, 'index'])
    ->name('pdl.index');

Route::get('/pdls/create', function () {
    return view('pdl.create');
})->name('pdl.create');

Route::post('/pdls', [\App\Http\Controllers\PdlController::class, 'store'])
    ->name('pdl.store');

Route::get('/pdls/{pdl}', [\App\Http\Controllers\PdlController::class, 'show'])
    ->name('pdl.show');

Route::put('/pdls/{pdl}', [\App\Http\Controllers\PdlController::class, 'update'])
    ->name('pdl.update');

Route::post('/pdls/{pdl}/legal-records', [\App\Http\Controllers\PdlController::class, 'storeLegalRecord'])
    ->name('pdl.legal-records.store');

Route::post('/pdls/{pdl}/disciplinary-records', [\App\Http\Controllers\PdlController::class, 'storeDisciplinaryRecord'])
    ->name('pdl.disciplinary-records.store');

Route::post('/pdls/{pdl}/restrictions', [\App\Http\Controllers\PdlController::class, 'storeRestriction'])
    ->name('pdl.restrictions.store');

Route::patch('/pdls/{pdl}/restrictions/{restriction}/lift', [\App\Http\Controllers\PdlController::class, 'liftRestriction'])
    ->name('pdl.restrictions.lift');


// --- Visitor Management ---

Route::get('/visitors', [\App\Http\Controllers\VisitorController::class, 'index'])
    ->name('visitor.index');

Route::get('/visitors/{visitor}', [\App\Http\Controllers\VisitorController::class, 'show'])
    ->name('visitor.show');

Route::post('/visitors/{visitor}/ids/{idDocument}/verify', [\App\Http\Controllers\VisitorController::class, 'verifyId'])
    ->name('visitor.ids.verify');

Route::post('/visitors/{visitor}/ids/{idDocument}/reject', [\App\Http\Controllers\VisitorController::class, 'rejectId'])
    ->name('visitor.ids.reject');

Route::post('/visitors/{visitor}/relationships/{relationship}/verify', [\App\Http\Controllers\VisitorController::class, 'verifyRelationship'])
    ->name('visitor.relationships.verify');

Route::post('/visitors/{visitor}/relationships/{relationship}/reject', [App\Http\Controllers\VisitorController::class, 'rejectRelationship'])
    ->name('visitor.relationships.reject');

Route::post('/visitors/{visitor}/flags', [App\Http\Controllers\VisitorController::class, 'storeFlag'])
    ->name('visitor.flags.store');

Route::post('/visitors/{visitor}/flags/{flag}/resolve', [App\Http\Controllers\VisitorController::class, 'resolveFlag'])
    ->name('visitor.flags.resolve');


// --- Eligibility Assessment ---

Route::get('/eligibility', [EligibilityController::class, 'index'])
    ->name('eligibility.index');

Route::post('/eligibility/run/{visitRequest}', [EligibilityController::class, 'store'])
    ->name('eligibility.run');

Route::post('/eligibility/{assessment}/review', [EligibilityController::class, 'review'])
    ->name('eligibility.review');

});


/*
|--------------------------------------------------------------------------
| Front Desk Officer
|--------------------------------------------------------------------------
| Front Desk handles visitor-facing gate operations such as the dashboard,
| check-in/check-out, today's schedule and visitor lookup.
*/

Route::prefix('front-desk')->name('frontdesk.')->middleware(['auth', 'role:Front Desk Officer'])->group(function () {

    Route::get('/', [FrontDeskDashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/checkin-checkout', [CheckinCheckoutController::class, 'index'])
        ->name('checkin-checkout');

    Route::post('/checkin-checkout/{visitRequest}/check-in', [CheckinCheckoutController::class, 'checkIn'])
        ->name('checkin-checkout.check-in');

    Route::post('/checkin-checkout/{checkin}/check-out', [CheckinCheckoutController::class, 'checkOut'])
        ->name('checkin-checkout.check-out');

    Route::get('/schedule', [FrontDeskScheduleController::class, 'index'])
        ->name('schedule');

    Route::get('/visitor-lookup', [VisitorLookupController::class, 'index'])
        ->name('visitor-lookup');

    Route::get('/settings', [FrontDeskSettingsController::class, 'index'])
        ->name('settings.index');

});