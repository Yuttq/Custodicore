<?php

namespace Tests\Feature;

use App\Models\VisitorId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Record Officer → visitor page (visitor/show): finished work is folded away,
 * what needs the officer stays open. Every action is still on the page.
 */
class RecordOfficerVisitorPageLayoutTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function page()
    {
        return $this->actingAs($this->recordOfficerAccount, 'web')
            ->get(route('visitor.show', $this->visitor->visitor_id))
            ->assertOk();
    }

    public function test_an_approved_account_shows_its_status_in_the_header_without_the_review_card(): void
    {
        $this->page()
            ->assertSee('APPROVED')
            ->assertDontSee('<h2>Account Review</h2>', false);
    }

    public function test_an_approved_relationship_is_folded_but_can_still_be_rejected(): void
    {
        $this->completeRequirements();

        $this->page()
            ->assertSee('Show requirements and files')
            ->assertSee(route('visitor.relationships.reject', [$this->visitor->visitor_id, $this->relationship->relationship_id]), false);
    }

    public function test_an_approved_relationship_offers_revoke_approval_not_reject(): void
    {
        $this->completeRequirements();

        $this->page()
            ->assertSee('Revoke Approval')
            ->assertSee('Revoke this approved relationship?')
            ->assertDontSee('>Reject</summary>', false);
    }

    public function test_revoking_an_approved_relationship_is_worded_as_a_revocation(): void
    {
        $this->completeRequirements();

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.relationships.reject', [$this->visitor->visitor_id, $this->relationship->relationship_id]),
                ['rejection_reason' => 'The certificate was found to be false.'])
            ->assertSessionHas('success', 'Relationship approval revoked.');

        $this->assertSame('rejected', $this->relationship->fresh()->verification_status);

        $notice = \App\Models\Notification::where('account_id', $this->visitor->account_id)
            ->where('notification_type', 'relationship_rejected')->sole();
        $this->assertSame('PDL Relationship Approval Revoked', $notice->title);
        $this->assertStringContainsString('is no longer approved', $notice->message);
        $this->assertStringContainsString('Reason: The certificate was found to be false.', $notice->message);

        $this->assertDatabaseHas('audit_logs', ['record_type' => 'visitor_pdl_relationships', 'record_id' => $this->relationship->relationship_id]);
    }

    public function test_a_pending_relationship_stays_open_with_its_actions(): void
    {
        $this->relationship->forceFill(['verification_status' => 'pending'])->save();

        $this->page()
            ->assertDontSee('Show requirements and files')
            ->assertSee(route('visitor.relationships.verify', [$this->visitor->visitor_id, $this->relationship->relationship_id]), false)
            ->assertSee(route('visitor.relationships.return', [$this->visitor->visitor_id, $this->relationship->relationship_id]), false)
            ->assertDontSee(route('visitor.relationships.reject', [$this->visitor->visitor_id, $this->relationship->relationship_id]), false);
    }

    public function test_sign_up_hints_show_only_while_a_relationship_is_pending(): void
    {
        $this->visitor->forceFill(['relationship_hint' => 'Spouse', 'intended_pdl_name' => 'Angel'])->save();

        $this->page()->assertDontSee('Relationship Hint (from registration)');

        $this->relationship->forceFill(['verification_status' => 'pending'])->save();
        $this->page()->assertSee('Relationship Hint (from registration)')->assertSee('Angel');
    }

    public function test_the_pending_id_placeholder_is_shown_as_no_number_given(): void
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'PENDING',
            'verification_status' => 'pending',
        ]);

        $this->page()->assertSee('No number given')->assertDontSee('<td class="muted-cell">PENDING</td>', false);
    }

    public function test_an_empty_flag_list_is_one_line_with_the_flag_action(): void
    {
        $this->page()->assertSee('None — clean history.')->assertSee('Flag This Visitor');
    }
}
