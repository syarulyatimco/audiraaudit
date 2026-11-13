<?php

namespace Tests\Feature\Completion;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Models\AuditReport;
use App\Models\CompletionItem;
use App\Models\EngagementCompletion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Planning\PlanningTestCase;

class CompletionTest extends PlanningTestCase
{
    private const PW = 'Password!2345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    private function answerChecklist(): void
    {
        $this->actingAs($this->ctx['senior'])->get($this->base().'/completion')->assertOk();
        foreach (CompletionItem::all() as $i) {
            $this->actingAs($this->ctx['senior'])->post($this->base()."/completion/items/{$i->uuid}", ['status' => 'done', 'response' => "Performed and documented for {$i->key}."])->assertSessionHasNoErrors();
        }
    }

    private function conclusions(): array
    {
        return ['misstatements_conclusion' => 'No uncorrected misstatements.', 'going_concern_conclusion' => 'No events or conditions identified.', 'overall_conclusion' => 'Sufficient appropriate evidence obtained.'];
    }

    private function reviewedPaper(): void
    {
        $b = $this->base();
        $this->actingAs($this->ctx['senior'])->post("{$b}/lead-schedules/".$this->fsliId('INV').'/paper')->assertRedirect();
        $wp = $this->ctx['engagement']->workingPapers()->where('reference', 'L-INV')->sole();
        $this->actingAs($this->ctx['senior'])->put("{$b}/working-papers/{$wp->uuid}", ['title' => $wp->title, 'conclusion' => 'Agreed.']);
        $this->actingAs($this->ctx['senior'])->post("{$b}/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['manager'])->post("{$b}/working-papers/{$wp->uuid}/signoff", ['role' => 'reviewer'])->assertSessionHasNoErrors();
    }

    private function approveCompletion(): EngagementCompletion
    {
        $b = $this->base();
        $this->answerChecklist();
        $this->actingAs($this->ctx['senior'])->put("{$b}/completion/conclusions", $this->conclusions())->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/conclusions")->assertSessionHasNoErrors();
        $c = EngagementCompletion::sole();
        $this->decide($c->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        $this->reviewedPaper();
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/completion")->assertSessionHasNoErrors();
        $this->decide($c->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();

        return $c->fresh();
    }

    private function decideWithPassword($approval, ?string $password = self::PW)
    {
        return $this->actingAs($this->ctx['partner'])->post("/approvals/{$approval->uuid}/decide", ['decision' => 'approve', 'password' => $password]);
    }

    public function test_checklist_conclusions_and_completion_gates(): void
    {
        $b = $this->base();
        $this->actingAs($this->ctx['senior'])->get("{$b}/completion")->assertOk()->assertSee('Completion checklist')->assertSee('Subsequent events (ISA 560)');
        $this->assertSame(count(config('audira.completion.items')), CompletionItem::count());
        $item = CompletionItem::where('key', 'litigation')->sole();
        $this->actingAs($this->ctx['staff'])->post("{$b}/completion/items/{$item->uuid}", ['status' => 'done', 'response' => 'x'])->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/items/{$item->uuid}", ['status' => 'not_applicable'])->assertSessionHasErrors('response');

        // Nothing can be submitted while gates fail.
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/conclusions")->assertSessionHasErrors('approval');
        $this->answerChecklist();
        $this->actingAs($this->ctx['senior'])->put("{$b}/completion/conclusions", $this->conclusions())->assertSessionHasNoErrors();
        // A misstatement awaiting management's response blocks the conclusions.
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings", ['type' => 'misstatement', 'title' => 'Cut-off', 'description' => 'x', 'engagement_fsli_id' => $this->fsliId('INV'), 'misstatement_class' => 'factual', 'amount' => '100'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/conclusions")->assertSessionHasErrors('approval');
        $f = \App\Models\Finding::sole();
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$f->uuid}/correction", ['correction_status' => 'uncorrected', 'uncorrected_reason' => 'Trivial in management\'s view.'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/conclusions")->assertSessionHasNoErrors();
        $c = EngagementCompletion::sole();
        $approval = $c->approvals()->pending()->sole();
        $this->assertSame('final_conclusions', $approval->type->value);
        $this->decide($approval, $this->ctx['manager'])->assertForbidden();
        $this->decide($approval, $this->ctx['partner'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $c->fresh()->conclusions_status->value);

        // The checklist is locked once conclusions are approved.
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/items/{$item->uuid}", ['status' => 'open'])->assertSessionHasErrors('status');

        // Completion needs reviewed working papers.
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/completion")->assertSessionHasErrors('approval');
        $this->reviewedPaper();
        $this->actingAs($this->ctx['senior'])->get("{$b}/completion")->assertOk()->assertSee('All working papers prepared and reviewed');
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/completion")->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->put("{$b}/completion/conclusions", $this->conclusions())->assertSessionHasErrors('conclusions');
        $this->decide($c->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $c->fresh()->completion_status->value);
    }

    public function test_engagement_quality_review_is_required_when_assigned(): void
    {
        $eqr = $this->staff($this->ctx['firm'], FirmRole::Partner);
        $this->ctx['engagement']->members()->forceCreate(['firm_id' => $this->ctx['firm']->id, 'user_id' => $eqr->id, 'role' => EngagementRole::EqrReviewer]);
        $b = $this->base();
        $this->answerChecklist();
        $this->actingAs($this->ctx['senior'])->put("{$b}/completion/conclusions", $this->conclusions())->assertSessionHasNoErrors();
        $this->actingAs($eqr)->post("{$b}/completion/eqr", ['eqr_conclusion' => 'Too early'])->assertSessionHasErrors('eqr_conclusion');
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/conclusions")->assertSessionHasNoErrors();
        $c = EngagementCompletion::sole();
        $this->decide($c->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        $this->reviewedPaper();

        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/submit/completion")->assertSessionHasErrors('approval'); // EQR outstanding
        $this->actingAs($this->ctx['senior'])->post("{$b}/completion/eqr", ['eqr_conclusion' => 'x'])->assertForbidden();
        $this->actingAs($eqr)->post("{$b}/completion/eqr", ['eqr_conclusion' => 'Significant judgements and conclusions reviewed; no unresolved matters.'])->assertSessionHasNoErrors();
        $this->assertNotNull($c->fresh()->eqr_completed_at);

        // Changing the approved conclusions withdraws the approval and the EQR.
        $this->actingAs($this->ctx['senior'])->put("{$b}/completion/conclusions", ['overall_conclusion' => 'Revised.'] + $this->conclusions())->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertNull($c->conclusions_status);
        $this->assertNull($c->eqr_completed_at);
        $this->actingAs($eqr)->get("{$b}/completion")->assertOk()->assertSee('Engagement quality review');
    }

    public function test_opinion_and_report_signoff_with_reauthentication_lock_the_engagement(): void
    {
        $b = $this->base();
        $this->approveCompletion();
        $this->actingAs($this->ctx['senior'])->put("{$b}/audit-report", ['opinion' => 'unmodified'])->assertForbidden(); // seniors do not prepare the report
        $this->actingAs($this->ctx['manager'])->put("{$b}/audit-report", ['opinion' => 'qualified'])->assertSessionHasErrors('basis_for_modification');
        $this->actingAs($this->ctx['manager'])->put("{$b}/audit-report", ['opinion' => 'unmodified', 'report_date' => '2025-12-01'])->assertSessionHasErrors('report_date');
        $this->actingAs($this->ctx['manager'])->put("{$b}/audit-report", ['opinion' => 'unmodified', 'report_date' => now()->toDateString(), 'action' => 'generate'])->assertSessionHasNoErrors();
        $r = AuditReport::sole();
        $this->assertStringContainsString("INDEPENDENT AUDITORS' REPORT TO THE MEMBERS OF", $r->body);
        $this->assertStringContainsString($this->ctx['client']->name, $r->body);
        $this->assertStringContainsString('[Insert your firm', $r->body, 'no standard wording is shipped');

        // Opinion: decided by the partner with re-authentication.
        $this->actingAs($this->ctx['manager'])->post("{$b}/audit-report/submit/opinion")->assertSessionHasNoErrors();
        $op = $r->approvals()->pending()->sole();
        $this->decide($op, $this->ctx['partner'])->assertSessionHasErrors('password');
        $this->decideWithPassword($op, 'wrong-password')->assertSessionHasErrors('password');
        $this->decideWithPassword($op)->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->fresh()->opinion_status->value);

        // Sign-off is blocked while the firm's wording is missing.
        $this->actingAs($this->ctx['manager'])->post("{$b}/audit-report/submit/signoff")->assertSessionHasErrors('approval');
        $this->actingAs($this->ctx['manager'])->get('/firm/report-templates')->assertForbidden();
        $this->actingAs($this->ctx['partner'])->put('/firm/report-templates/unmodified', ['body' => 'Report to {{entity_name}} {{bogus}}'])->assertSessionHasErrors('body');
        $this->actingAs($this->ctx['partner'])->put('/firm/report-templates/unmodified', ['body' => "INDEPENDENT AUDITORS' REPORT\n\nTo the members of {{entity_name}}\n\nOur approved wording for the year ended {{period_end}}.\n\n{{emphasis_of_matter}}\n\n{{firm_name}}\n{{partner_name}}\n{{report_date}}"])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['partner'])->get('/firm/report-templates')->assertOk()->assertSee('Our approved wording');
        $this->actingAs($this->ctx['manager'])->put("{$b}/audit-report", ['opinion' => 'unmodified', 'report_date' => now()->toDateString(), 'action' => 'generate'])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertStringContainsString('year ended 31 December 2025', $r->body);
        $this->assertStringNotContainsString("\n\n\n", $r->body);
        $this->assertSame('approved', $r->opinion_status->value, 'the opinion itself did not change');

        $this->actingAs($this->ctx['manager'])->post("{$b}/audit-report/submit/signoff")->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['manager'])->put("{$b}/audit-report", ['opinion' => 'unmodified', 'body' => 'x'])->assertSessionHasErrors('report');
        $so = $r->approvals()->pending()->sole();
        $this->assertSame('report_signoff', $so->type->value);
        $this->decide($so, $this->ctx['partner'])->assertSessionHasErrors('password');
        $this->decideWithPassword($so)->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(['signed', $this->ctx['partner']->id, $r->bodyHash()], [$r->status, $r->signed_by, $r->body_sha256]);
        $this->assertTrue($r->fs_snapshot['balanced']);
        $this->assertSame('signed_off', $this->ctx['engagement']->fresh()->status->value);
        try {
            DB::transaction(fn () => DB::table('audit_reports')->where('id', $r->id)->update(['body' => 'tampered']));
            $this->fail('signed reports must be immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        // Locked engagement: pages are read-only.
        $this->actingAs($this->ctx['manager'])->get("{$b}/audit-report")->assertOk()->assertSee('Signed by')->assertDontSee('Generate text from template');
        $this->actingAs($this->ctx['manager'])->get("{$b}/audit-report?print=1")->assertOk()->assertSee('Our approved wording');
        $this->actingAs($this->ctx['staff'])->get("{$b}/financial-statements")->assertOk()->assertSee('As signed')->assertSee('Statement of financial position');
        $this->actingAs($this->ctx['manager'])->post("{$b}/audit-report/submit/signoff")->assertSessionHasErrors();
    }

    public function test_financial_statements_render_from_adjusted_tb(): void
    {
        $this->actingAs($this->ctx['staff'])->get($this->base().'/financial-statements')->assertOk()
            ->assertSee('Draft — from the adjusted trial balance', false)->assertSee('Statement of profit or loss')->assertSee('Inventories');
        $fs = app(\App\Services\Reporting\FinancialStatements::class)->build($this->ctx['engagement']);
        $this->assertTrue($fs['balanced']);
        $this->assertSame(15_000_000, $fs['cy']['profit']);
    }
}
