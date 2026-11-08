<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * cc:purge-visitors: a dry run changes nothing; --force deletes every
 * visitor account with its visits, documents, tokens and files, and keeps
 * PDLs, staff and schedules.
 */
class PurgeVisitorsCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->publishSessions('2026-10-01', '2026-11-30');

        // A visitor with a photo, a government ID, a relationship document
        // and an approved visit (QR-able, with booked sessions).
        Storage::disk('local')->put('visitor-photos/p.jpg', 'x');
        Storage::disk('local')->put('visitor-ids/id.png', 'x');
        Storage::disk('local')->put('relationship-documents/doc.png', 'x');
        Storage::disk('public')->put('visitor-ids/legacy.png', 'x');
        Storage::disk('local')->put('pdl-photos/keep.jpg', 'x');

        $this->visitor->update(['photo_path' => 'visitor-photos/p.jpg']);
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'visitor-ids/id.png',
            'verification_status' => 'verified',
        ]);
        $this->completeRequirements();
        DB::table('relationship_documents')->where('visitor_id', $this->visitor->visitor_id)
            ->update(['file_path' => 'relationship-documents/doc.png']);
        $this->pdl->update(['photo_path' => 'pdl-photos/keep.jpg']);

        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => '2026-10-09',
            'startTimes' => ['09:00'],
        ])->assertCreated()->json('id');
        app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));

        // A QR pass that was scanned: visit_checkins.qr_code_id has no
        // cascade, which blocked the first purge attempt on MySQL.
        $qrId = DB::table('qr_codes')->insertGetId([
            'visit_request_id' => $id,
            'qr_token' => str_repeat('a', 64),
            'generated_at' => now(),
            'status' => 'used',
        ], 'qr_code_id');
        DB::table('visit_checkins')->insert([
            'visit_request_id' => $id,
            'schedule_id' => DB::table('visit_sessions')->where('visit_request_id', $id)->value('schedule_id'),
            'qr_code_id' => $qrId,
            'status' => 'checked_out',
        ]);

        // An older ID still on the legacy public disk.
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'passport',
            'id_number' => 'ID-2',
            'file_path' => 'visitor-ids/legacy.png',
            'verification_status' => 'rejected',
        ]);

        $this->visitorAccount->createToken('mobile');
        Notification::notify($this->recordOfficerAccount->account_id, 'new_visitor', 'New visitor', 'x', 'visitor_profiles', $this->visitor->visitor_id);
        Notification::notify($this->recordOfficerAccount->account_id, 'other', 'Unrelated', 'x');
        DB::table('password_reset_tokens')->insert(['email' => $this->visitorAccount->email, 'token' => 'h', 'created_at' => now()]);
    }

    public function test_a_dry_run_lists_the_visitors_and_deletes_nothing(): void
    {
        $this->artisan('cc:purge-visitors')
            ->expectsOutputToContain($this->visitorAccount->email)
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();

        $this->assertDatabaseHas('accounts', ['account_id' => $this->visitorAccount->account_id]);
        $this->assertSame(1, VisitRequest::count());
        Storage::disk('local')->assertExists('visitor-photos/p.jpg');
    }

    public function test_a_wrong_confirmation_deletes_nothing(): void
    {
        $this->artisan('cc:purge-visitors', ['--force' => true])
            ->expectsQuestion('This cannot be undone. Type "DELETE ALL VISITORS" to continue', 'yes')
            ->assertFailed();

        $this->assertDatabaseHas('accounts', ['account_id' => $this->visitorAccount->account_id]);
    }

    public function test_force_deletes_every_visitor_and_their_data_and_files_but_keeps_pdls_and_staff(): void
    {
        $this->artisan('cc:purge-visitors', ['--force' => true])
            ->expectsQuestion('This cannot be undone. Type "DELETE ALL VISITORS" to continue', 'DELETE ALL VISITORS')
            ->assertSuccessful();

        $this->assertDatabaseMissing('accounts', ['account_id' => $this->visitorAccount->account_id]);
        foreach (['visitor_profiles', 'visitor_ids', 'visitor_pdl_relationships', 'relationship_documents',
            'visit_requests', 'visit_sessions', 'qr_codes', 'visit_checkins', 'personal_access_tokens', 'password_reset_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }
        $this->assertSame(['Unrelated'], Notification::pluck('title')->all());

        Storage::disk('local')->assertMissing('visitor-photos/p.jpg');
        Storage::disk('local')->assertMissing('visitor-ids/id.png');
        Storage::disk('public')->assertMissing('visitor-ids/legacy.png');
        Storage::disk('local')->assertMissing('relationship-documents/doc.png');

        // Kept: PDL (and its photo), staff accounts, schedules, sessions.
        $this->assertTrue(Pdl::whereKey($this->pdl->pdl_id)->exists());
        Storage::disk('local')->assertExists('pdl-photos/keep.jpg');
        $this->assertTrue(Account::whereKey($this->recordOfficerAccount->account_id)->exists());
        $this->assertTrue(Account::whereKey($this->frontDeskAccount->account_id)->exists());
        $this->assertGreaterThan(0, DB::table('visit_schedules')->count());

        // The same email can register again.
        $this->assertFalse(Account::where('email', $this->visitorAccount->email)->exists());
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'delete', 'record_type' => 'accounts']);
    }

    public function test_it_reports_when_there_are_no_visitors(): void
    {
        $this->artisan('cc:purge-visitors', ['--force' => true, '--yes' => true])->assertSuccessful();
        $this->artisan('cc:purge-visitors')
            ->expectsOutputToContain('There are no visitor accounts')
            ->assertSuccessful();
    }
}
