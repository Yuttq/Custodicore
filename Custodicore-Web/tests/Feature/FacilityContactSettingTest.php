<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Support\FacilityContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Help Center contact: the Admin/Warden sets it on Settings (password
 * re-entered), the visitor app reads it from GET /api/facility-contact.
 * No phone number → no contact in the app (never a made-up one).
 */
class FacilityContactSettingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const ADMIN_PASSWORD = 'Correct-admin-pass1';

    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->admin = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden.contact',
            'email' => 'warden.contact@example.com',
            'password_hash' => Hash::make(self::ADMIN_PASSWORD),
            'status' => 'active',
        ]);
        StaffProfile::create([
            'account_id' => $this->admin->account_id,
            'employee_number' => 'EMP-900',
            'first_name' => 'Ana',
            'last_name' => 'Domingo',
            'position' => 'Warden',
            'contact_number' => '09171234567',
            'personal_email' => 'warden.contact@example.com',
        ]);
    }

    private function save(array $data, ?string $password = self::ADMIN_PASSWORD)
    {
        return $this->actingAs($this->admin, 'web')
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.facility-contact'), array_merge(
                ['name' => '', 'phone' => '', 'hours' => ''],
                $data,
                $password === null ? [] : ['current_password' => $password],
            ));
    }

    private function appContact()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->visitorAccount->createToken('mobile')->plainTextToken)
            ->getJson('/api/facility-contact');
    }

    public function test_app_shows_no_contact_until_a_phone_number_is_set(): void
    {
        $this->appContact()->assertOk()->assertExactJson(['contact' => null]);
    }

    public function test_admin_sets_the_contact_and_the_app_shows_it(): void
    {
        $this->save([
            'name' => '  Visiting Area   Desk ',
            'phone' => '(032)  123-4567',
            'hours' => 'Mon–Fri, 8:00 AM – 5:00 PM',
        ])->assertRedirect(route('admin.settings.index'))->assertSessionHas('status');

        $this->appContact()->assertOk()->assertExactJson(['contact' => [
            'name' => 'Visiting Area Desk',
            'phone' => '(032) 123-4567',
            'hours' => 'Mon–Fri, 8:00 AM – 5:00 PM',
        ]]);

        $this->assertDatabaseHas('audit_logs', ['record_type' => 'system_settings', 'account_id' => $this->admin->account_id]);
    }

    public function test_settings_page_shows_the_form_with_current_values(): void
    {
        $this->save(['phone' => '+63 32 123 4567']);

        $this->actingAs($this->admin, 'web')->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Facility Contact')
            ->assertSee('+63 32 123 4567', false)
            // Not listed again in the generic System Settings table.
            ->assertDontSee('facility_contact.phone');
    }

    public function test_clearing_the_phone_hides_the_contact_again(): void
    {
        $this->save(['phone' => '(032) 123-4567']);
        $this->save(['name' => 'Visiting Area', 'phone' => '']);

        $this->appContact()->assertOk()->assertExactJson(['contact' => null]);
    }

    public function test_wrong_or_missing_password_saves_nothing(): void
    {
        $this->save(['phone' => '(032) 123-4567'], 'wrong-password')->assertSessionHasErrors('current_password');
        $this->save(['phone' => '(032) 123-4567'], null)->assertSessionHasErrors('current_password');

        $this->assertSame('', FacilityContact::current()['phone']);
    }

    public function test_phone_number_must_be_a_plain_number(): void
    {
        foreach (['javascript:alert(1)', 'tel:123', '12345', '0917abc4567', '0917;1234567', '123-456-7890-123-456-78'] as $bad) {
            $this->save(['phone' => $bad])->assertSessionHasErrors('phone');
        }

        $this->assertSame('', FacilityContact::current()['phone']);
    }

    public function test_name_and_hours_are_stripped_of_markup_and_limited(): void
    {
        $this->save(['name' => '<script>x</script>Desk', 'phone' => '0917 123 4567', 'hours' => '<b>Daily</b>']);

        $this->assertSame(['name' => 'xDesk', 'phone' => '0917 123 4567', 'hours' => 'Daily'], FacilityContact::current());

        $this->save(['name' => str_repeat('a', FacilityContact::NAME_MAX + 1), 'phone' => '0917 123 4567'])
            ->assertSessionHasErrors('name');
    }

    public function test_only_the_admin_can_change_it_and_only_signed_in_visitors_can_read_it(): void
    {
        $officer = Account::create([
            'role_id' => Role::where('role_name', 'Record Officer')->value('role_id'),
            'username' => 'officer.contact',
            'email' => 'officer.contact@example.com',
            'password_hash' => Hash::make('officer-pass'),
            'status' => 'active',
        ]);

        $this->actingAs($officer, 'web')
            ->put(route('admin.settings.facility-contact'), ['phone' => '(032) 123-4567', 'current_password' => 'officer-pass'])
            ->assertForbidden();
        $this->assertSame('', FacilityContact::current()['phone']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/facility-contact')->assertUnauthorized();
    }
}
