<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Models\Pdl;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitSchedule;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal fixture builder for Phase 1 feature tests (sqlite :memory:).
 */
trait SeedsVisitAssignmentFixtures
{
    protected Role $visitorRole;
    protected Role $recordOfficerRole;
    protected Role $frontDeskRole;
    protected Account $recordOfficerAccount;
    protected Account $frontDeskAccount;
    protected Account $visitorAccount;
    protected VisitorProfile $visitor;
    protected Pdl $pdl;
    protected VisitorPdlRelationship $relationship;
    protected VisitSchedule $schedule;
    protected FacilityVisitationRule $rule;

    protected function seedPhase1Fixtures(): void
    {
        foreach ([
            Module::CODE_USER_MANAGEMENT => 'User Management',
            Module::CODE_PDL_MANAGEMENT => 'PDL Management',
            Module::CODE_VISITOR_MANAGEMENT => 'Visitor Management',
            Module::CODE_VISIT_SCHEDULING => 'Visit Scheduling',
            Module::CODE_CHECKIN_CHECKOUT => 'QR Check-In and Check-Out',
            Module::CODE_ELIGIBILITY_ASSESSMENT => 'Visitor Eligibility Assessment',
            Module::CODE_AUDIT_REPORTING => 'Audit Trail and Basic Reporting',
            Module::CODE_CUSTODY_HISTORY => 'Custody History',
            Module::CODE_VISITATION_TRACKING => 'Visitation Tracking',
        ] as $code => $name) {
            Module::create(['module_code' => $code, 'module_name' => $name]);
        }

        $this->visitorRole = Role::create(['role_name' => 'Visitor', 'description' => 'Visitor']);
        $this->recordOfficerRole = Role::create(['role_name' => 'Record Officer', 'description' => 'RO']);
        $this->frontDeskRole = Role::create(['role_name' => 'Front Desk Officer', 'description' => 'FD']);
        Role::create(['role_name' => 'System Administrator/Warden', 'description' => 'Admin']);

        $this->recordOfficerAccount = Account::create([
            'role_id' => $this->recordOfficerRole->role_id,
            'username' => 'r.salcedo',
            'email' => 'r.salcedo@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        StaffProfile::create([
            'account_id' => $this->recordOfficerAccount->account_id,
            'employee_number' => 'EMP-014',
            'full_name' => 'Rico P. Salcedo',
            'position' => 'Records Officer',
        ]);

        $this->frontDeskAccount = Account::create([
            'role_id' => $this->frontDeskRole->role_id,
            'username' => 'n.fernandez',
            'email' => 'n.fernandez@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        StaffProfile::create([
            'account_id' => $this->frontDeskAccount->account_id,
            'employee_number' => 'EMP-031',
            'full_name' => 'Noel D. Fernandez',
            'position' => 'Front Desk Officer',
        ]);

        $this->visitorAccount = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'maria.santos',
            'email' => 'maria.santos@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        // Phase 2: an existing visitor who already verified their email.
        $this->visitorAccount->forceFill(['email_verified_at' => now()])->save();
        $this->visitor = VisitorProfile::create([
            'account_id' => $this->visitorAccount->account_id,
            'full_name' => 'Maria D. Santos',
            'date_of_birth' => '1985-03-14',
            'gender' => 'female',
            'contact_number' => '09171234567',
            'verification_status' => 'verified',
        ]);

        $registrar = StaffProfile::first();
        $this->pdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-001',
            'full_name' => 'Example PDL',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => $registrar->staff_id,
        ]);

        $this->relationship = VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'immediate_family',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        $this->rule = FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 2,
            'effective_from' => '2024-01-01',
        ]);

        // Next Friday from today
        $nextFriday = now()->next('Friday');
        if ($nextFriday->isPast()) {
            $nextFriday = $nextFriday->addWeek();
        }

        $this->schedule = VisitSchedule::create([
            'rule_id' => $this->rule->rule_id,
            'schedule_date' => $nextFriday->toDateString(),
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 2,
            'slots_taken' => 0,
            'status' => 'open',
        ]);

        SystemSetting::create([
            'setting_key' => 'visit.confirmation_window_hours',
            'setting_value' => '48',
            'updated_by' => StaffProfile::first()->staff_id,
        ]);
    }
}
