<?php

namespace Database\Seeders;

use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

/**
 * The rows CustodiCore needs to run at all — roles, modules, the role
 * permission matrix and the facility's base visiting rules. No accounts,
 * people or visits: safe for production and safe to run again (every row is
 * firstOrCreate). Production setup:
 *
 *   php artisan db:seed --class=ReferenceDataSeeder --force
 *   php artisan cc:create-first-warden ...
 *
 * DatabaseSeeder runs this first, then adds demo data where allowed.
 */
class ReferenceDataSeeder extends Seeder
{
    public const ROLES = [
        'System Administrator/Warden' => 'Full system access — the Warden dashboard.',
        'Record Officer' => 'PDL, Visitor, Scheduling, Eligibility, Custody History and Visitation Tracking.',
        'Front Desk Officer' => 'Gate operations — schedule lookup and QR check-in/out.',
        'Visitor' => 'Mobile app only — own profile, own schedules and QR.',
    ];

    public function run(): void
    {
        $roles = $this->seedRoles();
        $modules = $this->seedModules();
        $this->seedRolePermissions($roles, $modules);
        $this->seedFacilityVisitationRules();
    }

    /**
     * The reference rows already in the database, for DatabaseSeeder's demo data.
     *
     * @return array{0: array<string, Role>, 1: array<string, Module>, 2: list<FacilityVisitationRule>}
     */
    public static function existing(): array
    {
        $roles = Role::whereIn('role_name', array_keys(self::ROLES))->get()->keyBy('role_name')->all();
        $modules = Module::whereIn('module_code', array_keys(self::moduleNames()))->get()->keyBy('module_code')->all();

        $rules = [];
        foreach (self::ruleSpec() as [$classification, $day, $start]) {
            $rules[] = FacilityVisitationRule::where([
                'pdl_classification' => $classification, 'day_of_week' => $day, 'time_slot_start' => $start,
            ])->firstOrFail();
        }

        return [$roles, $modules, $rules];
    }

    // -----------------------------------------------------------------
    private function seedRoles(): array
    {
        $roles = [];
        foreach (self::ROLES as $name => $description) {
            $roles[$name] = Role::firstOrCreate(['role_name' => $name], ['description' => $description]);
        }

        return $roles;
    }

    /** @return array<string, string> module code => name */
    private static function moduleNames(): array
    {
        return [
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
    }

    private function seedModules(): array
    {
        $modules = [];
        foreach (self::moduleNames() as $code => $name) {
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

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> classification, day, slot start, slot end */
    private static function ruleSpec(): array
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
                $rules[] = [$classification, $day, $start, $end];
            }
        }

        return $rules;
    }

    private function seedFacilityVisitationRules(): array
    {
        $rules = [];
        foreach (self::ruleSpec() as [$classification, $day, $start, $end]) {
            $rules[] = FacilityVisitationRule::firstOrCreate(
                ['pdl_classification' => $classification, 'day_of_week' => $day, 'time_slot_start' => $start],
                ['time_slot_end' => $end, 'max_capacity' => 30, 'effective_from' => '2024-01-01']
            );
        }

        return $rules;
    }
}
