<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EligibilityAssessment;
use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Models\Pdl;
use App\Models\PdlRestriction;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitCheckin;
use App\Models\VisitorFlag;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Real seed data for CustodiCore. Runs once (`php artisan migrate:fresh
 * --seed` or `php artisan db:seed`) and gives every dashboard — the Warden
 * admin dashboard, the Records Officer module, the Front Desk module, and
 * the mobile app — the SAME underlying rows to read from, instead of each
 * one making up its own disconnected sample data.
 *
 * The people/PDLs below deliberately reuse the exact same names, employee
 * numbers, PDL numbers, etc. that were previously hardcoded as sample
 * arrays in Admin\UserController / Admin\PdlController / Admin\VisitorController
 * / Admin\ScheduleController / Admin\EligibilityController — so switching
 * those controllers from fake session data to real Eloquent queries (see
 * that rewrite) doesn't change what's on screen, just where it comes from.
 *
 * Default password for every seeded account: "password"
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $roles = $this->seedRoles();
        $modules = $this->seedModules();
        $this->seedRolePermissions($roles, $modules);
        $rules = $this->seedFacilityVisitationRules();

        [$staff, $staffAccounts] = $this->seedStaff($roles);
        [$visitors, $visitorAccounts] = $this->seedVisitors($roles, $staff);

        $pdls = $this->seedPdls($staff);
        $this->seedRestrictions($pdls, $staff);
        $relationships = $this->seedRelationships($visitors, $pdls);
        $this->seedVisitorFlags($visitors, $staff);

        $schedules = $this->seedSchedules($rules);
        $visitRequests = $this->seedVisitRequests($visitors, $pdls, $relationships, $schedules);
        $this->assessAndCheckIn($visitRequests, $staff);

        $this->seedSystemSettings($staff);
        $this->seedSampleAuditTrail($staffAccounts, $modules, $pdls);
    }

    // -----------------------------------------------------------------
    private function seedRoles(): array
    {
        $names = [
            'System Administrator/Warden' => 'Full system access — the Warden dashboard.',
            'Record Officer' => 'PDL, Visitor, Scheduling, Eligibility, Custody History and Visitation Tracking.',
            'Front Desk Officer' => 'Gate operations — schedule lookup and QR check-in/out.',
            'Visitor' => 'Mobile app only — own profile, own schedules and QR.',
        ];

        $roles = [];
        foreach ($names as $name => $description) {
            $roles[$name] = Role::firstOrCreate(['role_name' => $name], ['description' => $description]);
        }

        return $roles;
    }

    private function seedModules(): array
    {
        $names = [
            Module::CODE_USER_MANAGEMENT => 'User Management',
            Module::CODE_PDL_MANAGEMENT => 'PDL Management',
            Module::CODE_VISITOR_MANAGEMENT => 'Visitor Management',
            Module::CODE_VISIT_SCHEDULING => 'Visit Scheduling',
            Module::CODE_CHECKIN_CHECKOUT => 'QR Check-In and Check-Out',
            Module::CODE_ELIGIBILITY_ASSESSMENT => 'Visitor Eligibility Assessment',
            Module::CODE_AUDIT_REPORTING => 'Audit Trail and Basic Reporting',
            Module::CODE_CUSTODY_HISTORY => 'Custody History',
            Module::CODE_VISITATION_TRACKING => 'Visitation Tracking',
        ];

        $modules = [];
        foreach ($names as $code => $name) {
            $modules[$code] = Module::firstOrCreate(['module_code' => $code], ['module_name' => $name]);
        }

        return $modules;
    }

    private function seedRolePermissions(array $roles, array $modules): void
    {
        // Warden: full view access everywhere, write access only on User
        // Management — matches the Admin dashboard's deliberate read-only
        // behavior everywhere except registering/editing officer accounts.
        foreach ($modules as $code => $module) {
            RolePermission::firstOrCreate(
                ['role_id' => $roles['System Administrator/Warden']->role_id, 'module_id' => $module->module_id],
                [
                    'can_view' => true,
                    'can_create' => $code === Module::CODE_USER_MANAGEMENT,
                    'can_edit' => $code === Module::CODE_USER_MANAGEMENT,
                    'can_delete' => false,
                ]
            );
        }

        $recordOfficerFull = [
            Module::CODE_PDL_MANAGEMENT, Module::CODE_VISITOR_MANAGEMENT, Module::CODE_VISIT_SCHEDULING,
            Module::CODE_ELIGIBILITY_ASSESSMENT, Module::CODE_CUSTODY_HISTORY, Module::CODE_VISITATION_TRACKING,
        ];
        foreach ($recordOfficerFull as $code) {
            RolePermission::firstOrCreate(
                ['role_id' => $roles['Record Officer']->role_id, 'module_id' => $modules[$code]->module_id],
                ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => false]
            );
        }
        RolePermission::firstOrCreate(
            ['role_id' => $roles['Record Officer']->role_id, 'module_id' => $modules[Module::CODE_AUDIT_REPORTING]->module_id],
            ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false]
        );

        RolePermission::firstOrCreate(
            ['role_id' => $roles['Front Desk Officer']->role_id, 'module_id' => $modules[Module::CODE_VISIT_SCHEDULING]->module_id],
            ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false]
        );
        RolePermission::firstOrCreate(
            ['role_id' => $roles['Front Desk Officer']->role_id, 'module_id' => $modules[Module::CODE_CHECKIN_CHECKOUT]->module_id],
            ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => false]
        );
    }

    private function seedFacilityVisitationRules(): array
    {
        // section 3.4 of the brief: drug_related -> Thu/Sat, non_drug_related
        // -> Fri/Sun; two slots each day; 30 visitors per slot.
        $spec = [
            ['drug_related', 'thu'], ['drug_related', 'sat'],
            ['non_drug_related', 'fri'], ['non_drug_related', 'sun'],
        ];
        $slots = [['09:00:00', '11:30:00'], ['13:00:00', '16:30:00']];

        $rules = [];
        foreach ($spec as [$classification, $day]) {
            foreach ($slots as [$start, $end]) {
                $rules[] = FacilityVisitationRule::firstOrCreate(
                    ['pdl_classification' => $classification, 'day_of_week' => $day, 'time_slot_start' => $start],
                    ['time_slot_end' => $end, 'max_capacity' => 30, 'effective_from' => '2024-01-01']
                );
            }
        }

        return $rules;
    }

    // -----------------------------------------------------------------
    private function seedStaff(array $roles): array
    {
        $rows = [
            ['EMP-001', 'Ana R. Domingo', 'a.domingo@bjmp.gov.ph', 'System Administrator/Warden', 'Warden', 'active'],
            ['EMP-014', 'Rico P. Salcedo', 'r.salcedo@bjmp.gov.ph', 'Record Officer', 'Records Officer', 'active'],
            ['EMP-022', 'Grace T. Manalo', 'g.manalo@bjmp.gov.ph', 'Record Officer', 'Records Officer', 'active'],
            ['EMP-031', 'Noel D. Fernandez', 'n.fernandez@bjmp.gov.ph', 'Front Desk Officer', 'Front Desk Officer', 'active'],
            ['EMP-037', 'Jhoana C. Reyes', 'j.reyes@bjmp.gov.ph', 'Front Desk Officer', 'Front Desk Officer', 'inactive'],
        ];

        $staff = [];
        $accounts = [];
        foreach ($rows as [$empNo, $name, $email, $roleName, $position, $status]) {
            $username = Str::before($email, '@');

            $account = Account::firstOrCreate(
                ['email' => $email],
                [
                    'role_id' => $roles[$roleName]->role_id,
                    'username' => $username,
                    'password_hash' => Hash::make('password'),
                    'status' => $status,
                ]
            );

            $profile = StaffProfile::firstOrCreate(
                ['account_id' => $account->account_id],
                [
                    'employee_number' => $empNo,
                    'full_name' => $name,
                    'position' => $position,
                    'assigned_facility' => 'BJMP Facility — Main',
                ]
            );

            $staff[$name] = $profile;
            $accounts[$name] = $account;
        }

        return [$staff, $accounts];
    }

    private function seedVisitors(array $roles, array $staff): array
    {
        $rows = [
            ['Maria D. Santos', 'maria.santos@example.com', '0917 123 4567', 'verified', '1985-03-14'],
            ['Carlo J. Ramos', 'carlo.ramos@example.com', '0928 555 0199', 'verified', '1979-11-02'],
            ['Liza P. Aquino', 'liza.aquino@example.com', '0906 442 8871', 'pending', '1992-07-21'],
            ['Dante R. Cabrera', 'dante.cabrera@example.com', '0933 210 7745', 'pending', '1988-05-09'],
            ['Fe M. Lopez', 'fe.lopez@example.com', '0917 887 3302', 'rejected', '1975-01-30'],
        ];

        $verifier = $staff['Grace T. Manalo'] ?? reset($staff);

        $visitors = [];
        $accounts = [];
        foreach ($rows as [$name, $email, $contact, $status, $dob]) {
            $username = Str::before($email, '@');

            $account = Account::firstOrCreate(
                ['email' => $email],
                [
                    'role_id' => $roles['Visitor']->role_id,
                    'username' => $username,
                    'password_hash' => Hash::make('password'),
                    'status' => 'active',
                ]
            );

            $profile = VisitorProfile::firstOrCreate(
                ['account_id' => $account->account_id],
                [
                    'full_name' => $name,
                    'date_of_birth' => $dob,
                    'gender' => 'other',
                    'contact_number' => $contact,
                    'verification_status' => $status,
                    'verified_by' => $status !== 'pending' ? $verifier->staff_id : null,
                    'verified_at' => $status !== 'pending' ? now()->subDays(10) : null,
                ]
            );

            if ($status !== 'pending') {
                VisitorId::firstOrCreate(
                    ['visitor_id' => $profile->visitor_id, 'id_type' => 'national_id'],
                    [
                        'id_number' => 'ID-' . str_pad((string) $profile->visitor_id, 6, '0', STR_PAD_LEFT),
                        'file_path' => 'seed/placeholder-id.jpg',
                        'verification_status' => $status,
                        'verified_by' => $verifier->staff_id,
                        'verified_at' => now()->subDays(10),
                    ]
                );
            }

            $visitors[$name] = $profile;
            $accounts[$name] = $account;
        }

        return [$visitors, $accounts];
    }

    private function seedPdls(array $staff): array
    {
        $rows = [
            ['PDL-0032', 'Ramon G. Bautista', 'male', 'non_drug_related', 'Dorm 3', 'active', '2024-02-10'],
            ['PDL-0014', 'Ellen M. Cruz', 'female', 'drug_related', 'Dorm 1', 'active', '2023-11-05'],
            ['PDL-0055', 'Jerome S. Villareal', 'male', 'drug_related', 'Dorm 2', 'active', '2024-04-18'],
            ['PDL-0091', 'Vicente A. Torres', 'male', 'non_drug_related', 'Dorm 4', 'transferred', '2022-09-30'],
            ['PDL-0102', 'Bea L. Santiago', 'female', 'non_drug_related', 'Dorm 5 (F)', 'active', '2024-06-01'],
        ];

        $registrar = $staff['Rico P. Salcedo'] ?? reset($staff);

        $pdls = [];
        foreach ($rows as [$number, $name, $gender, $classification, $cell, $status, $admitted]) {
            $pdls[$number] = Pdl::firstOrCreate(
                ['pdl_number' => $number],
                [
                    'full_name' => $name,
                    'date_of_birth' => '1990-01-01',
                    'gender' => $gender,
                    'classification' => $classification,
                    'cell_block' => $cell,
                    'admission_date' => $admitted,
                    'custody_status' => $status,
                    'registered_by' => $registrar->staff_id,
                ]
            );
        }

        return $pdls;
    }

    private function seedRestrictions(array $pdls, array $staff): void
    {
        $warden = $staff['Ana R. Domingo'] ?? reset($staff);

        PdlRestriction::firstOrCreate(
            ['pdl_id' => $pdls['PDL-0014']->pdl_id, 'restriction_type' => 'quarantine'],
            ['status' => 'active', 'start_date' => now()->subDays(5), 'reason' => 'Medical quarantine per infirmary order', 'imposed_by' => $warden->staff_id]
        );

        PdlRestriction::firstOrCreate(
            ['pdl_id' => $pdls['PDL-0102']->pdl_id, 'restriction_type' => 'disciplinary'],
            ['status' => 'active', 'start_date' => now()->subDays(12), 'reason' => 'Disciplinary infraction — dorm altercation', 'imposed_by' => $warden->staff_id]
        );
        PdlRestriction::firstOrCreate(
            ['pdl_id' => $pdls['PDL-0102']->pdl_id, 'restriction_type' => 'court_order'],
            ['status' => 'active', 'start_date' => now()->subDays(30), 'reason' => 'Court-ordered restriction pending case review', 'imposed_by' => $warden->staff_id]
        );
    }

    private function seedRelationships(array $visitors, array $pdls): array
    {
        $rows = [
            ['Maria D. Santos', 'PDL-0032', 'immediate_family', 'high_priority', 'verified'],
            ['Carlo J. Ramos', 'PDL-0014', 'legal_counsel', 'high_priority', 'verified'],
            ['Liza P. Aquino', 'PDL-0055', 'approved_relative', 'requires_verification', 'pending'],
            ['Dante R. Cabrera', 'PDL-0091', 'unknown_or_other', 'requires_verification', 'pending'],
            ['Fe M. Lopez', 'PDL-0102', 'immediate_family', 'high_priority', 'rejected'],
        ];

        $relationships = [];
        foreach ($rows as [$visitorName, $pdlNumber, $type, $tier, $status]) {
            $relationships[$visitorName] = VisitorPdlRelationship::firstOrCreate(
                ['visitor_id' => $visitors[$visitorName]->visitor_id, 'pdl_id' => $pdls[$pdlNumber]->pdl_id],
                [
                    'relationship_type' => $type,
                    'priority_tier' => $tier,
                    'verification_status' => $status,
                    'verified_at' => $status === 'verified' ? now()->subDays(8) : null,
                ]
            );
        }

        return $relationships;
    }

    private function seedVisitorFlags(array $visitors, array $staff): void
    {
        $officer = $staff['Grace T. Manalo'] ?? reset($staff);

        VisitorFlag::firstOrCreate(
            ['visitor_id' => $visitors['Dante R. Cabrera']->visitor_id, 'flag_type' => 'rule_violation'],
            ['description' => 'Attempted to bring prohibited item during previous visit', 'flagged_by' => $officer->staff_id, 'status' => 'active']
        );

        VisitorFlag::firstOrCreate(
            ['visitor_id' => $visitors['Fe M. Lopez']->visitor_id, 'flag_type' => 'denied_visit'],
            ['description' => 'Denied entry — expired ID', 'flagged_by' => $officer->staff_id, 'status' => 'resolved', 'resolved_by' => $officer->staff_id, 'resolved_at' => now()->subDays(3)]
        );
    }

    // -----------------------------------------------------------------
    private function seedSchedules(array $rules): array
    {
        // Next occurrence of each rule's day-of-week, with slots_taken set
        // to mirror what was previously hardcoded sample capacity in
        // Admin\ScheduleController (28/30, 19/30, 30/30, 12/30).
        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

        $taken = [
            'drug_related|thu|09:00:00' => 28,
            'drug_related|thu|13:00:00' => 19,
            'non_drug_related|fri|09:00:00' => 30,
            'drug_related|sat|13:00:00' => 12,
        ];

        $schedules = [];
        foreach ($rules as $rule) {
            $target = $dayMap[$rule->day_of_week];
            $date = now()->copy()->next($target);

            $key = "{$rule->pdl_classification}|{$rule->day_of_week}|{$rule->time_slot_start}";

            $schedules[$key] = VisitSchedule::firstOrCreate(
                ['rule_id' => $rule->rule_id, 'schedule_date' => $date->toDateString(), 'time_slot_start' => $rule->time_slot_start],
                [
                    'time_slot_end' => $rule->time_slot_end,
                    'max_capacity' => $rule->max_capacity,
                    'slots_taken' => $taken[$key] ?? 0,
                    'status' => ($taken[$key] ?? 0) >= $rule->max_capacity ? 'full' : 'open',
                ]
            );
        }

        return $schedules;
    }

    private function seedVisitRequests(array $visitors, array $pdls, array $relationships, array $schedules): array
    {
        $drugThu = $schedules['drug_related|thu|09:00:00'] ?? reset($schedules);
        $drugSat = $schedules['drug_related|sat|13:00:00'] ?? reset($schedules);
        $nonDrugFri = $schedules['non_drug_related|fri|09:00:00'] ?? reset($schedules);

        $requests = [];

        // Maria -> Ramon (PDL-0032, non_drug_related): confirmed, already checked in/out below.
        $requests['maria'] = VisitRequest::firstOrCreate(
            [
                'visitor_id' => $visitors['Maria D. Santos']->visitor_id,
                'pdl_id' => $pdls['PDL-0032']->pdl_id,
            ],
            [
                'relationship_id' => $relationships['Maria D. Santos']->relationship_id,
                'schedule_id' => $nonDrugFri->schedule_id,
                'status' => 'confirmed',
                'confirmed_at' => now()->subDays(2),
            ]
        );

        // Carlo -> Ellen (PDL-0014, drug_related): assigned, upcoming.
        $requests['carlo'] = VisitRequest::firstOrCreate(
            [
                'visitor_id' => $visitors['Carlo J. Ramos']->visitor_id,
                'pdl_id' => $pdls['PDL-0014']->pdl_id,
            ],
            [
                'relationship_id' => $relationships['Carlo J. Ramos']->relationship_id,
                'schedule_id' => $drugThu->schedule_id,
                'status' => 'assigned',
                'confirmation_deadline' => now()->addDays(2),
            ]
        );

        // Liza -> Jerome (PDL-0055, drug_related): pending confirmation, relationship
        // still unverified — this is the one that should land in the eligibility
        // review queue.
        $requests['liza'] = VisitRequest::firstOrCreate(
            [
                'visitor_id' => $visitors['Liza P. Aquino']->visitor_id,
                'pdl_id' => $pdls['PDL-0055']->pdl_id,
            ],
            [
                'relationship_id' => $relationships['Liza P. Aquino']->relationship_id,
                'schedule_id' => $drugSat->schedule_id,
                'status' => 'pending_confirmation',
                'confirmation_deadline' => now()->addDays(1),
            ]
        );

        return $requests;
    }

    private function assessAndCheckIn(array $visitRequests, array $staff): void
    {
        foreach ($visitRequests as $visitRequest) {
            EligibilityAssessment::assessFor($visitRequest->fresh());
        }

        // Give Maria's (confirmed) visit request a full gate history — QR
        // generated, checked in, and checked out — so Front Desk / mobile
        // "visit history" both have a real completed record to show.
        $officer = $staff['Noel D. Fernandez'] ?? reset($staff);
        $maria = $visitRequests['maria']->fresh();

        $qr = QrCode::generateFor($maria);
        $qr->update(['status' => 'used']);

        VisitCheckin::firstOrCreate(
            ['visit_request_id' => $maria->visit_request_id],
            [
                'qr_code_id' => $qr->qr_code_id,
                'check_in_time' => now()->subDays(2)->setTime(9, 4),
                'check_in_officer_id' => $officer->staff_id,
                'check_out_time' => now()->subDays(2)->setTime(11, 20),
                'check_out_officer_id' => $officer->staff_id,
                'verification_method' => 'qr_scan',
                'status' => 'checked_out',
            ]
        );

        $maria->update(['status' => 'completed']);
    }

    private function seedSystemSettings(array $staff): void
    {
        $warden = $staff['Ana R. Domingo'] ?? reset($staff);

        $settings = [
            'visit.max_per_week' => '2',
            'visit.confirmation_window_hours' => '48',
            'qr.expiry_minutes' => '180',
            'schedule.slot_capacity_default' => '30',
        ];

        foreach ($settings as $key => $value) {
            SystemSetting::firstOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value, 'updated_by' => $warden->staff_id]
            );
        }
    }

    private function seedSampleAuditTrail(array $staffAccounts, array $modules, array $pdls): void
    {
        // A few realistic-looking entries so the Audit Trail page (which
        // now reads the real audit_logs table instead of the old session
        // helper) isn't empty on first load.
        $entries = [
            ['r.salcedo@bjmp.gov.ph', 'update', Module::CODE_PDL_MANAGEMENT, 'pdl_profiles', $pdls['PDL-0055']->pdl_id, 'Updated disciplinary record for PDL-0055', now()->subHours(3)],
            ['n.fernandez@bjmp.gov.ph', 'check_in', Module::CODE_CHECKIN_CHECKOUT, 'visit_checkins', 1, 'Checked in visitor Maria D. Santos', now()->subDays(2)->setTime(9, 4)],
            ['g.manalo@bjmp.gov.ph', 'update', Module::CODE_ELIGIBILITY_ASSESSMENT, 'eligibility_assessments', 1, 'Reviewed flagged assessment for Liza P. Aquino', now()->subHours(20)],
            ['a.domingo@bjmp.gov.ph', 'update', Module::CODE_PDL_MANAGEMENT, 'pdl_custody_status', $pdls['PDL-0102']->pdl_id, 'Custody status: active -> active (no change on record)', now()->subDays(1)],
        ];

        foreach ($entries as [$email, $action, $moduleCode, $recordType, $recordId, $description, $createdAt]) {
            $accountId = collect($staffAccounts)->first(fn ($a) => $a->email === $email)?->account_id;

            AuditLog::create([
                'account_id' => $accountId,
                'action_type' => $action,
                'module_id' => $modules[$moduleCode]->module_id,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'description' => $description,
                'ip_address' => '127.0.0.1',
                'created_at' => $createdAt,
            ]);
        }
    }
}
