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

use App\Http\Controllers\LegalController;
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

Route::post('/logout', [LoginController::class, 'logout'])
    ->name('logout')
    ->middleware('auth');

/*
|--------------------------------------------------------------------------
| Terms & Conditions / Privacy Policy
|--------------------------------------------------------------------------
| Public on purpose — people read these BEFORE registering. Text comes from
| config/legal.php (the mobile app gets the same text from GET /api/legal).
*/

Route::get('/terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');

/*
|--------------------------------------------------------------------------
| Home
|--------------------------------------------------------------------------
| Sends a logged-in officer straight to their own dashboard.
*/

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return match (auth()->user()->role?->role_name) {
        'System Administrator/Warden' => redirect()->route('admin.dashboard'),
        'Record Officer' => redirect()->route('dashboard'),
        'Front Desk Officer' => redirect()->route('frontdesk.dashboard'),

        default => redirect()
            ->route('login')
            ->with(
                'status',
                'This account type has no web dashboard — use the mobile app instead.'
            ),
    };
})->name('home');


/*
|--------------------------------------------------------------------------
| System Administrator / Warden dashboard
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware([
        'auth',
        'role:System Administrator/Warden'
    ])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get('/', [DashboardController::class, 'index'])
            ->name('dashboard');


        /*
        |--------------------------------------------------------------------------
        | User Management
        |--------------------------------------------------------------------------
        */

        Route::get('/users', [UserController::class, 'index'])
            ->name('users.index');

        Route::post('/users', [UserController::class, 'store'])
            ->name('users.store');

        // Changing an existing officer's details / status requires the
        // admin to re-enter their own password as the final step.
        Route::patch('/users/{id}', [UserController::class, 'update'])
            ->middleware('reauth')
            ->name('users.update');

        Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus'])
            ->middleware('reauth')
            ->name('users.toggle-status');


        /*
        |--------------------------------------------------------------------------
        | PDL Management
        |--------------------------------------------------------------------------
        */

        Route::get('/pdls', [PdlController::class, 'index'])
            ->name('pdls.index');


        /*
        |--------------------------------------------------------------------------
        | Visitor Management
        |--------------------------------------------------------------------------
        */

        Route::get('/visitors', [VisitorController::class, 'index'])
            ->name('visitors.index');


        /*
        |--------------------------------------------------------------------------
        | Schedule Management
        |--------------------------------------------------------------------------
        */

        Route::get('/schedules', [ScheduleController::class, 'index'])
            ->name('schedules.index');


        /*
        |--------------------------------------------------------------------------
        | Check-In / Check-Out
        |--------------------------------------------------------------------------
        */

        Route::get('/checkins', [CheckinController::class, 'index'])
            ->name('checkins.index');


        /*
        |--------------------------------------------------------------------------
        | Audit Trail
        |--------------------------------------------------------------------------
        */

        Route::get('/audit', [AuditController::class, 'index'])
            ->name('audit.index');


        /*
        |--------------------------------------------------------------------------
        | Settings
        |--------------------------------------------------------------------------
        */

        Route::get('/settings', [SettingsController::class, 'index'])
            ->name('settings.index');
    });


/*
|--------------------------------------------------------------------------
| Records Officer module
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'role:Record Officer'
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/dashboard',
        [\App\Http\Controllers\DashboardController::class, 'index']
    )->name('dashboard');


    /*
    |--------------------------------------------------------------------------
    | Custody History
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/custody-history',
        [CustodyHistoryController::class, 'index']
    )->name('custody-history.index');


    /*
    |--------------------------------------------------------------------------
    | Visitation Tracking
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/visitation-tracking',
        [VisitationTrackingController::class, 'index']
    )->name('visitation-tracking.index');

    Route::get(
        '/visitation-tracking/{pdl}',
        [VisitationTrackingController::class, 'show']
    )->name('visitation-tracking.show');


    /*
    |--------------------------------------------------------------------------
    | PDL Management
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/pdls',
        [\App\Http\Controllers\PdlController::class, 'index']
    )->name('pdl.index');

    Route::get('/pdls/create', function () {
        return view('pdl.create');
    })->name('pdl.create');

    Route::post(
        '/pdls',
        [\App\Http\Controllers\PdlController::class, 'store']
    )->name('pdl.store');

    Route::get(
        '/pdls/{pdl}',
        [\App\Http\Controllers\PdlController::class, 'show']
    )->name('pdl.show');

    // Editing a PDL's details requires the Record Officer to re-enter
    // their own password as the final step.
    Route::put(
        '/pdls/{pdl}',
        [\App\Http\Controllers\PdlController::class, 'update']
    )->middleware('reauth')->name('pdl.update');

    Route::post(
        '/pdls/{pdl}/legal-records',
        [\App\Http\Controllers\PdlController::class, 'storeLegalRecord']
    )->name('pdl.legal-records.store');

    Route::post(
        '/pdls/{pdl}/disciplinary-records',
        [\App\Http\Controllers\PdlController::class, 'storeDisciplinaryRecord']
    )->name('pdl.disciplinary-records.store');

    Route::post(
        '/pdls/{pdl}/restrictions',
        [\App\Http\Controllers\PdlController::class, 'storeRestriction']
    )->name('pdl.restrictions.store');

    Route::patch(
        '/pdls/{pdl}/restrictions/{restriction}/lift',
        [\App\Http\Controllers\PdlController::class, 'liftRestriction']
    )->name('pdl.restrictions.lift');


    /*
    |--------------------------------------------------------------------------
    | Visitor Management
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/visitors',
        [\App\Http\Controllers\VisitorController::class, 'index']
    )->name('visitor.index');

    Route::get(
        '/visitors/{visitor}',
        [\App\Http\Controllers\VisitorController::class, 'show']
    )->name('visitor.show');

    Route::post(
        '/visitors/{visitor}/ids/{idDocument}/verify',
        [\App\Http\Controllers\VisitorController::class, 'verifyId']
    )->name('visitor.ids.verify');

    Route::post(
        '/visitors/{visitor}/ids/{idDocument}/reject',
        [\App\Http\Controllers\VisitorController::class, 'rejectId']
    )->name('visitor.ids.reject');

    Route::post(
        '/visitors/{visitor}/relationships/{relationship}/verify',
        [\App\Http\Controllers\VisitorController::class, 'verifyRelationship']
    )->name('visitor.relationships.verify');

    Route::post(
        '/visitors/{visitor}/relationships/{relationship}/reject',
        [App\Http\Controllers\VisitorController::class, 'rejectRelationship']
    )->name('visitor.relationships.reject');

    Route::post(
        '/visitors/{visitor}/flags',
        [App\Http\Controllers\VisitorController::class, 'storeFlag']
    )->name('visitor.flags.store');

    Route::post(
        '/visitors/{visitor}/flags/{flag}/resolve',
        [App\Http\Controllers\VisitorController::class, 'resolveFlag']
    )->name('visitor.flags.resolve');

    Route::post(
        '/visitors/{visitor}/visits/assign',
        [\App\Http\Controllers\VisitAssignmentController::class, 'store']
    )->name('visitor.visits.assign');


    /*
    |--------------------------------------------------------------------------
    | Eligibility Assessment
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/eligibility',
        [EligibilityController::class, 'index']
    )->name('eligibility.index');

    Route::post(
        '/eligibility/run/{visitRequest}',
        [EligibilityController::class, 'store']
    )->name('eligibility.run');

    Route::post(
        '/eligibility/{assessment}/review',
        [EligibilityController::class, 'review']
    )->name('eligibility.review');
});


/*
|--------------------------------------------------------------------------
| Front Desk Officer
|--------------------------------------------------------------------------
| Front Desk handles visitor-facing gate operations:
|
| - Dashboard
| - QR Check-In / Check-Out
| - Today's Schedule
| - Visitor Lookup
| - Settings
|
| IMPORTANT CHECK-IN FLOW:
|
| QR Scan
|     ↓
| Verify QR / Visitor / Schedule
|     ↓
| Display Visitor Information
|     ↓
| Front Desk verifies surrendered ID
|     ↓
| Confirm Check-In
|     ↓
| Create VisitCheckin record
|     ↓
| Mark QR as used
|     ↓
| Audit the transaction
|--------------------------------------------------------------------------
*/

Route::prefix('front-desk')
    ->name('frontdesk.')
    ->middleware([
        'auth',
        'role:Front Desk Officer'
    ])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Front Desk Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/',
            [FrontDeskDashboardController::class, 'index']
        )->name('dashboard');


        /*
        |--------------------------------------------------------------------------
        | Check-In / Check-Out
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/checkin-checkout',
            [CheckinCheckoutController::class, 'index']
        )->name('checkin-checkout');


        /*
        |--------------------------------------------------------------------------
        | QR Code Scanner
        |--------------------------------------------------------------------------
        |
        | The scanner only verifies the QR code.
        |
        | It does NOT check the visitor in yet.
        |
        */

        Route::post(
            '/checkin-checkout/scan',
            [CheckinCheckoutController::class, 'scanQr']
        )->name('checkin-checkout.scan');


        /*
        |--------------------------------------------------------------------------
        | Confirm QR Check-In
        |--------------------------------------------------------------------------
        |
        | This is the actual check-in action.
        |
        | The Front Desk Officer must first:
        |
        | 1. Scan the QR
        | 2. Review visitor information
        | 3. Verify the visitor's ID
        | 4. Select the surrendered ID type
        | 5. Click Confirm Check-In
        |
        */

        Route::post(
            '/checkin-checkout/confirm',
            [CheckinCheckoutController::class, 'confirmQrCheckIn']
        )->name('checkin-checkout.confirm');


        /*
        |--------------------------------------------------------------------------
        | Manual Check-In
        |--------------------------------------------------------------------------
        |
        | Backup option when QR scanning cannot be used.
        |
        */

        Route::post(
            '/checkin-checkout/{visitRequest}/check-in',
            [CheckinCheckoutController::class, 'checkIn']
        )->name('checkin-checkout.check-in');


        /*
        |--------------------------------------------------------------------------
        | Check-Out
        |--------------------------------------------------------------------------
        |
        | Used when a visitor leaves the facility.
        |
        */

        Route::post(
            '/checkin-checkout/{checkin}/check-out',
            [CheckinCheckoutController::class, 'checkOut']
        )->name('checkin-checkout.check-out');


        /*
        |--------------------------------------------------------------------------
        | Today's Schedule
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/schedule',
            [FrontDeskScheduleController::class, 'index']
        )->name('schedule');


        /*
        |--------------------------------------------------------------------------
        | Visitor Lookup
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/visitor-lookup',
            [VisitorLookupController::class, 'index']
        )->name('visitor-lookup');


        /*
        |--------------------------------------------------------------------------
        | Front Desk Settings
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/settings',
            [FrontDeskSettingsController::class, 'index']
        )->name('settings.index');

    });