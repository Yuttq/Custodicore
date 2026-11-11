<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\RelationshipDocument;
use App\Models\VisitorPdlRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * A pending PDL link has one set of decisions — Approve, Return (what to fix)
 * or Reject — instead of a Reject on every file.
 */
class RelationshipReturnTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private VisitorPdlRelationship $link;
    private RelationshipDocument $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->relationship->update(['relationship_type' => 'parent', 'verification_status' => 'pending']);
        $this->link = $this->relationship->fresh();
        $this->file = RelationshipDocument::create([
            'visitor_id' => $this->visitor->visitor_id, 'relationship_id' => $this->link->relationship_id,
            'requirement_key' => 'pdl_birth_certificate', 'file_path' => 'x.png', 'status' => 'pending', 'uploaded_at' => now(),
        ]);
    }

    private function returnUrl(): string
    {
        return route('visitor.relationships.return', [$this->visitor->visitor_id, $this->link->relationship_id]);
    }

    public function test_return_sends_the_link_back_with_what_to_fix(): void
    {
        $this->post($this->returnUrl(), ['rejection_reason' => 'The birth certificate is unreadable.'])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Returned to'));

        $this->assertSame('pending', $this->link->fresh()->verification_status);
        $this->assertSame('rejected', $this->file->fresh()->status);
        $this->assertSame('The birth certificate is unreadable.', $this->file->fresh()->rejection_reason);

        $notice = Notification::where('account_id', $this->visitor->account_id)->where('notification_type', 'relationship_returned')->sole();
        $this->assertStringContainsString('The birth certificate is unreadable.', $notice->message);
    }

    public function test_return_needs_a_reason_and_a_pending_link(): void
    {
        $this->post($this->returnUrl(), ['rejection_reason' => 'No'])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $this->file->fresh()->status);

        $this->link->update(['verification_status' => 'verified']);
        $this->post($this->returnUrl(), ['rejection_reason' => 'The birth certificate is unreadable.'])
            ->assertSessionHas('error', 'Only a pending relationship can be returned.');
    }

    public function test_pages_show_approve_return_reject_and_no_reject_per_file(): void
    {
        foreach ([route('visitor.show', $this->visitor->visitor_id), route('pdl-links.index')] as $page) {
            $this->get($page)->assertOk()
                ->assertSee($this->returnUrl(), false)
                ->assertDontSee(route('relationship-documents.reject', $this->file->document_id), false);
        }
    }

    private function guardianLink(): VisitorPdlRelationship
    {
        \App\Models\VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'x.png', 'verification_status' => 'verified',
        ]);
        $this->link->update(['relationship_type' => 'legal_guardian']);
        $this->file->update(['requirement_key' => 'guardianship_authorization']);

        return $this->link->fresh();
    }

    public function test_approve_is_the_officer_confirmation_with_no_confirm_button_or_tick_box(): void
    {
        $link = $this->guardianLink();
        $approve = route('visitor.relationships.verify', [$this->visitor->visitor_id, $link->relationship_id]);

        // No Confirm button and no tick box: the Approve pop-up states what it confirms.
        $this->get(route('visitor.show', $this->visitor->visitor_id))->assertOk()
            ->assertDontSee(route('visitor.relationships.confirm', [$this->visitor->visitor_id, $link->relationship_id, 'no_immediate_family']), false)
            ->assertDontSee('name="confirmations[]"', false)
            ->assertSee('Confirmed when you approve')
            ->assertSee('By approving, you confirm ' . $this->pdl->full_name . ' has no immediate family available to visit');

        // One click: the confirmation, the file and the relationship.
        $this->post($approve)->assertSessionHas('success');
        $link->refresh();
        $this->assertSame('verified', $link->verification_status);
        $this->assertSame('verified', $this->file->fresh()->status);
        $this->assertNotNull($link->confirmation('no_immediate_family'));
    }

    public function test_a_minors_companion_comes_from_the_registered_guardian(): void
    {
        \App\Models\VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'x.png', 'verification_status' => 'verified',
        ]);
        // A 12-year-old child of the PDL, registered with a guardian.
        $this->visitor->update(['date_of_birth' => now()->subYears(12)->toDateString(), 'guardian_full_name' => 'Ana Reyes']);
        $this->link->update(['relationship_type' => 'child']);
        $this->file->update(['requirement_key' => 'visitor_birth_certificate']);
        $approve = route('visitor.relationships.verify', [$this->visitor->visitor_id, $this->link->relationship_id]);

        $this->get(route('visitor.show', $this->visitor->visitor_id))->assertOk()
            ->assertSee('the minor will be accompanied by Ana Reyes (their registered guardian)')
            ->assertDontSee('name="accompanying_adult"', false);

        $this->post($approve)->assertSessionHas('success');
        $this->assertSame('Ana Reyes', $this->link->fresh()->confirmation('accompanying_guardian')['note']);
    }
}
