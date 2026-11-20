<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Services\ScheduleAvailabilityService;
use App\Support\FacilityContact;
use App\Support\Sanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

/** Settings page: facility contact, visiting rules and the role -> module access matrix (Module 1.1). */
class SettingsController extends Controller
{
    public function index(): View
    {
        $roles = Role::with(['rolePermissions.module'])->orderBy('role_id')->get()->map(function ($role) {
            if ($role->role_name === 'Visitor') {
                return ['role_name' => $role->role_name, 'access' => 'Own profile, own schedules and QR (mobile app only)'];
            }

            $full = $role->rolePermissions->filter(fn ($p) => $p->can_create || $p->can_edit)->pluck('module.module_name')->filter()->all();
            $viewOnly = $role->rolePermissions->filter(fn ($p) => $p->can_view && ! $p->can_create && ! $p->can_edit)->pluck('module.module_name')->filter()->all();

            $parts = [];
            if ($full) {
                $parts[] = implode(', ', $full) . ' (view/create/edit)';
            }
            if ($viewOnly) {
                $parts[] = implode(', ', $viewOnly) . ' (view only)';
            }

            return [
                'role_name' => $role->role_name,
                'access' => $parts ? implode('; ', $parts) : 'No module access configured yet',
            ];
        })->all();

        $summary = [
            ['label' => 'Roles Defined', 'value' => (string) count($roles), 'icon' => 'users', 'accent' => 'success'],
        ];

        $facilityContact = FacilityContact::current();
        // The limit bookings use now (2 when the row does not exist yet).
        $maxPerWeek = app(ScheduleAvailabilityService::class)->maxVisitsPerWeek();

        return view('admin.settings.index', compact('roles', 'summary', 'facilityContact', 'maxPerWeek'));
    }

    /**
     * PUT /admin/settings/visiting-rules — the weekly visit limit per visitor
     * (all PDLs together). Behind `reauth`. New bookings, staff assignments,
     * walk-ins and the app calendar read the setting on every check, so the
     * change applies at once; visits already booked are left as they are.
     */
    public function updateVisitingRules(Request $request, ScheduleAvailabilityService $availability): RedirectResponse
    {
        $data = $request->validate([
            'max_per_week' => ['required', 'integer', 'between:'.SystemSetting::MAX_PER_WEEK_MIN.','.SystemSetting::MAX_PER_WEEK_MAX],
        ], [
            'max_per_week.between' => 'Enter a number from '.SystemSetting::MAX_PER_WEEK_MIN.' to '.SystemSetting::MAX_PER_WEEK_MAX.'.',
            'max_per_week.integer' => 'Enter a whole number.',
        ]);

        $staffId = $request->user()?->staffProfile?->staff_id;
        abort_unless($staffId, 403, 'Only staff with a profile can change settings.');

        $before = $availability->maxVisitsPerWeek();
        $after = (int) $data['max_per_week'];

        DB::transaction(function () use ($before, $after, $staffId) {
            $setting = SystemSetting::updateOrCreate(
                ['setting_key' => SystemSetting::KEY_MAX_PER_WEEK],
                ['setting_value' => (string) $after, 'updated_by' => $staffId, 'updated_at' => now()]
            );

            if ($before !== $after) {
                AuditLog::record(
                    'update',
                    'system_settings',
                    (int) $setting->setting_id,
                    "Changed weekly visit limit from {$before} to {$after} per visitor",
                    Module::CODE_VISIT_SCHEDULING
                );
            }
        });

        return redirect()->route('admin.settings.index')
            ->with('status', $before !== $after
                ? "Weekly visit limit set to {$after}. It applies to new bookings now; visits already booked are not changed."
                : "Weekly visit limit is still {$after}.");
    }

    /**
     * PUT /admin/settings/facility-contact — the contact shown in the
     * visitor app's Help Center. Behind `reauth` (the admin re-enters their
     * password): a wrong number here would send visitors' calls elsewhere.
     * An empty phone number hides the contact in the app.
     */
    public function updateFacilityContact(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => Sanitizer::text($request->input('name')),
            'phone' => FacilityContact::normalizePhone($request->input('phone')),
            'hours' => Sanitizer::text($request->input('hours')),
        ]);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:'.FacilityContact::NAME_MAX],
            'phone' => [
                'nullable', 'string', 'regex:'.FacilityContact::PHONE_REGEX,
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (preg_match_all('/\d/', (string) $value) < FacilityContact::PHONE_MIN_DIGITS) {
                        $fail('Enter a phone number with at least '.FacilityContact::PHONE_MIN_DIGITS.' digits.');
                    }
                },
            ],
            'hours' => ['nullable', 'string', 'max:'.FacilityContact::HOURS_MAX],
        ], [
            'phone.regex' => 'Use only digits, spaces, +, ( ) and -, e.g. (032) 123-4567.',
        ]);

        $staffId = $request->user()?->staffProfile?->staff_id;
        abort_unless($staffId, 403, 'Only staff with a profile can change settings.');

        $before = FacilityContact::current();
        FacilityContact::save($data, $staffId);
        $after = FacilityContact::current();

        if ($before !== $after) {
            AuditLog::record(
                'update',
                'system_settings',
                (int) SystemSetting::where('setting_key', FacilityContact::KEY_PHONE)->value('setting_id'),
                Str::limit(
                    'Updated Help Center facility contact: '
                    .($after['phone'] !== '' ? "{$after['phone']}" : 'hidden (no phone number)')
                    .($before['phone'] !== $after['phone'] ? " (was ".($before['phone'] !== '' ? $before['phone'] : 'none').')' : ''),
                    250
                ),
                Module::CODE_USER_MANAGEMENT
            );
        }

        return redirect()->route('admin.settings.index')
            ->with('status', $after['phone'] !== ''
                ? 'Facility contact saved. Visitors see it in the app\'s Help Center.'
                : 'Facility contact saved. It is hidden in the app until a phone number is set.');
    }
}
