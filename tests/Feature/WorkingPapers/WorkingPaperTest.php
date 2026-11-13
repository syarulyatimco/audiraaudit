<?php

namespace Tests\Feature\WorkingPapers;

use App\Enums\EngagementRole;
use App\Enums\EngagementStatus;
use App\Enums\FirmRole;
use App\Enums\WorkingPaperStatus;
use App\Models\WorkingPaper;
use Tests\TestCase;

class WorkingPaperTest extends TestCase
{
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        [$firm, $partner] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $senior = $this->staff($firm, FirmRole::Senior);
        $staff = $this->staff($firm);
        $eqr = $this->staff($firm, FirmRole::Partner);
        $engagement = $this->engagement($this->client($partner), $partner, [
            [$manager, EngagementRole::Manager], [$senior, EngagementRole::Senior], [$staff, EngagementRole::Staff], [$eqr, EngagementRole::EqrReviewer],
        ]);
        $this->ctx = compact('firm', 'partner', 'manager', 'senior', 'staff', 'eqr', 'engagement');
    }

    private function url(string $suffix = ''): string
    {
        return "/engagements/{$this->ctx['engagement']->uuid}/working-papers".$suffix;
    }

    private function paper(string $conclusion = 'Revenue is fairly stated in all material respects.'): WorkingPaper
    {
        ['staff' => $staff] = $this->ctx;
        $this->actingAs($staff)->post($this->url(), ['reference' => 'f1', 'title' => 'Revenue cut-off', 'section' => 'fieldwork'])->assertRedirect();
        $wp = WorkingPaper::where('reference', 'F1')->sole();
        if ($conclusion) {
            $this->actingAs($staff)->put($this->url("/{$wp->uuid}"), ['title' => 'Revenue cut-off', 'work_performed' => 'Tested 25 invoices either side of year end.', 'conclusion' => $conclusion])->assertSessionHasNoErrors();
        }

        return $wp->fresh();
    }

    public function test_full_signoff_cycle_with_segregation(): void
    {
        ['staff' => $staff, 'senior' => $senior, 'manager' => $manager] = $this->ctx;
        $wp = $this->paper();

        $this->actingAs($staff)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'reviewer'])->assertForbidden();
        $this->actingAs($staff)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'preparer'])->assertSessionHasNoErrors();
        $this->assertSame(WorkingPaperStatus::Prepared, $wp->fresh()->status);

        $this->actingAs($senior)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'reviewer'])->assertForbidden(); // seniors don't review
        $this->actingAs($manager)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'reviewer'])->assertSessionHasNoErrors();

        $wp->refresh();
        $this->assertSame(WorkingPaperStatus::Reviewed, $wp->status);
        $this->assertSame(2, $wp->activeSignoffs()->count());
        $this->assertTrue($wp->activeSignoffs()->get()->every(fn ($s) => $s->content_hash === $wp->content_hash));
    }

    public function test_preparer_needs_conclusion_and_cannot_review_own_paper(): void
    {
        ['manager' => $manager] = $this->ctx;
        $this->actingAs($manager)->post($this->url(), ['reference' => 'A1', 'title' => 'Planning memo', 'section' => 'planning']);
        $wp = WorkingPaper::where('reference', 'A1')->sole();

        $this->actingAs($manager)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'preparer'])->assertSessionHasErrors('conclusion');
        $this->actingAs($manager)->put($this->url("/{$wp->uuid}"), ['title' => 'Planning memo', 'conclusion' => 'Plan approved.']);
        $this->actingAs($manager)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'preparer'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'reviewer'])->assertForbidden();
    }

    public function test_editing_after_signoff_invalidates_and_keeps_history(): void
    {
        ['staff' => $staff, 'manager' => $manager] = $this->ctx;
        $wp = $this->paper();
        $this->actingAs($staff)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'preparer']);
        $this->actingAs($manager)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'reviewer']);
        $hash = $wp->fresh()->content_hash;

        $this->actingAs($staff)->put($this->url("/{$wp->uuid}"), ['title' => 'Revenue cut-off', 'work_performed' => 'Tested 25 invoices.', 'conclusion' => 'Changed conclusion.'])
            ->assertSessionHas('status', 'Saved. Existing sign-offs were invalidated because the content changed.');

        $wp->refresh();
        $this->assertSame(WorkingPaperStatus::Draft, $wp->status);
        $this->assertNotSame($hash, $wp->content_hash);
        $this->assertSame(0, $wp->activeSignoffs()->count());
        $this->assertSame(2, $wp->signoffs()->whereNotNull('invalidated_at')->count());
    }

    public function test_review_notes_block_review_and_follow_lifecycle(): void
    {
        ['staff' => $staff, 'manager' => $manager, 'partner' => $partner, 'eqr' => $eqr, 'senior' => $senior] = $this->ctx;
        $wp = $this->paper();
        $this->actingAs($staff)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'preparer']);

        $this->actingAs($eqr)->post($this->url("/{$wp->uuid}/notes"), ['body' => 'Extend cut-off testing to credit notes.'])->assertSessionHasNoErrors();
        $note = $wp->reviewNotes()->sole();

        $this->actingAs($manager)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'reviewer'])->assertSessionHasErrors('signoff');
        $this->actingAs($eqr)->post($this->url("/{$wp->uuid}/notes/{$note->uuid}/clear"))->assertSessionHasErrors('note'); // not yet responded

        $this->actingAs($staff)->post($this->url("/{$wp->uuid}/notes/{$note->uuid}/respond"), ['response' => 'Tested 10 credit notes; no exceptions.'])->assertSessionHasNoErrors();
        $this->actingAs($senior)->post($this->url("/{$wp->uuid}/notes/{$note->uuid}/clear"))->assertForbidden();
        $this->actingAs($eqr)->post($this->url("/{$wp->uuid}/notes/{$note->uuid}/clear"))->assertSessionHasNoErrors();

        $this->actingAs($manager)->post($this->url("/{$wp->uuid}/signoff"), ['role' => 'reviewer'])->assertSessionHasNoErrors();

        // a new note on a reviewed paper withdraws the review sign-off
        $this->actingAs($partner)->post($this->url("/{$wp->uuid}/notes"), ['body' => 'Document the threshold used.']);
        $this->assertSame(WorkingPaperStatus::Prepared, $wp->fresh()->status);
    }

    public function test_eqr_cannot_edit_and_outsiders_cannot_view(): void
    {
        ['eqr' => $eqr, 'firm' => $firm] = $this->ctx;
        $wp = $this->paper();
        $this->actingAs($eqr)->get($this->url("/{$wp->uuid}"))->assertOk();
        $this->actingAs($eqr)->put($this->url("/{$wp->uuid}"), ['title' => 'x'])->assertForbidden();

        $outsider = $this->staff($firm);
        $this->actingAs($outsider)->get($this->url())->assertForbidden();
        [, $foreign] = $this->makeFirm();
        $this->actingAs($foreign)->get($this->url("/{$wp->uuid}"))->assertNotFound();
    }

    public function test_locked_engagement_is_read_only(): void
    {
        ['staff' => $staff, 'engagement' => $engagement] = $this->ctx;
        $wp = $this->paper();
        $engagement->forceFill(['status' => EngagementStatus::SignedOff])->save();

        $this->actingAs($staff)->put($this->url("/{$wp->uuid}"), ['title' => 'Edited', 'conclusion' => 'x'])->assertForbidden();
        $this->actingAs($staff)->post($this->url(), ['reference' => 'F2', 'title' => 'New', 'section' => 'fieldwork'])->assertForbidden();
        $this->assertSame('Revenue cut-off', $wp->fresh()->title);
    }

    public function test_duplicate_reference_rejected_and_index_renders(): void
    {
        ['staff' => $staff] = $this->ctx;
        $this->paper();
        $this->actingAs($staff)->post($this->url(), ['reference' => 'F1', 'title' => 'Dup', 'section' => 'fieldwork'])->assertSessionHasErrors('reference');
        $this->actingAs($staff)->get($this->url())->assertOk()->assertSee('Revenue cut-off');
        $this->actingAs($staff)->get($this->url('/create'))->assertOk();
    }
}
