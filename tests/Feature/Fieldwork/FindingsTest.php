<?php

namespace Tests\Feature\Fieldwork;

use App\Models\Adjustment;
use App\Models\Finding;
use App\Models\Procedure;
use App\Services\Findings\AuditDifferences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Planning\PlanningTestCase;
use Tests\Support\BuildsSamples;

class FindingsTest extends PlanningTestCase
{
    use BuildsSamples;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    private function evaluatedSampleWithOneError(): \App\Models\SamplingPlan
    {
        $this->approvedMateriality(); // OM 7,500.00 · PM 5,625.00 · CT 375.00
        $plan = $this->selectedSample();
        $doc = $this->uploadDocument('confirmation-0001.txt', "Customer 1 confirms balance of RM 300.00 at 30 June 2025.");
        $items = $plan->sampleItems()->with('populationItem')->get();
        $url = $this->base()."/sampling/{$plan->uuid}";
        $this->actingAs($this->ctx['staff'])->post("{$url}/items/{$items[1]->id}", ['result' => 'exception', 'audited_amount' => '300.00', 'result_note' => 'Credit note not recorded', 'evidence_document' => '00000000-0000-0000-0000-000000000000'])->assertSessionHasErrors('evidence_document');
        $this->actingAs($this->ctx['staff'])->post("{$url}/items/{$items[1]->id}", ['result' => 'exception', 'audited_amount' => '300.00', 'result_note' => 'Credit note not recorded', 'evidence_document' => $doc->uuid])->assertSessionHasNoErrors();
        $this->assertSame($doc->id, $items[1]->fresh()->evidence_document_id);
        foreach ($items->slice(2)->prepend($items[0]) as $it) {
            $this->actingAs($this->ctx['staff'])->post("{$url}/items/{$it->id}", ['result' => 'no_exception'])->assertSessionHasNoErrors();
        }
        // The selection itself stays frozen even though evidence may be linked.
        try {
            DB::transaction(fn () => DB::table('sample_items')->where('id', $items[1]->id)->update(['selection_order' => 99]));
            $this->fail('selection must stay immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->actingAs($this->ctx['senior'])->post("{$url}/evaluate", ['conclusion' => 'One error; projected misstatement recorded as a finding.'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    public function test_findings_from_sample_feed_the_summary_of_audit_differences(): void
    {
        $plan = $this->evaluatedSampleWithOneError();
        $url = $this->base()."/sampling/{$plan->uuid}";
        $this->actingAs($this->ctx['staff'])->post("{$url}/findings")->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post("{$url}/findings")->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post("{$url}/findings")->assertSessionHasErrors('plan'); // once only

        [$factual, $projected] = Finding::orderBy('reference')->get()->all();
        $this->assertSame(['F01', 'factual', 30_000, 'sample'], [$factual->reference, $factual->misstatement_class->value, $factual->amount_sen, $factual->source]);
        // MUS projection 83,333 (0.5 tainting × interval 166,666) less the 30,000 found.
        $this->assertSame(['F02', 'projected', 53_333], [$projected->reference, $projected->misstatement_class->value, $projected->amount_sen]);
        $this->assertSame([$this->fsliId('TR'), $plan->id], [$projected->engagement_fsli_id, $projected->sampling_plan_id]);

        $sad = app(AuditDifferences::class)->build($this->ctx['engagement']);
        $this->assertSame(['F01'], $sad['trivial']->pluck('reference')->all(), '300.00 is below clearly trivial 375.00');
        $this->assertSame(['F02'], $sad['uncorrected']->pluck('reference')->all());
        $this->assertSame([53_333, 53_333], [$sad['net_sen'], $sad['gross_sen']]);
        $this->assertFalse($sad['flags']['aggregate_at_or_above_pm']);
        $this->assertSame(1, $sad['flags']['awaiting_management']);

        // Management declines to correct: a reason is required. Correction requires a posted adjustment.
        $c = $this->base()."/findings/{$projected->uuid}/correction";
        $this->actingAs($this->ctx['senior'])->post($c, ['correction_status' => 'uncorrected'])->assertSessionHasErrors('uncorrected_reason');
        $this->actingAs($this->ctx['senior'])->post($c, ['correction_status' => 'corrected'])->assertSessionHasErrors('adjustment');
        $this->actingAs($this->ctx['senior'])->post($c, ['correction_status' => 'uncorrected', 'uncorrected_reason' => 'Management considers the projected amount immaterial.'])->assertSessionHasNoErrors();
        $this->assertSame(0, app(AuditDifferences::class)->build($this->ctx['engagement'])['flags']['awaiting_management']);

        // A judgemental misstatement large enough to breach overall materiality in aggregate.
        $this->actingAs($this->ctx['senior'])->post($this->base().'/findings', ['type' => 'misstatement', 'title' => 'Inventory provision understated', 'description' => 'Our estimate of the NRV provision exceeds management\'s.',
            'engagement_fsli_id' => $this->fsliId('INV'), 'misstatement_class' => 'judgemental', 'amount' => '7,000.00'])->assertSessionHasNoErrors();
        $sad = app(AuditDifferences::class)->build($this->ctx['engagement']);
        $this->assertSame(753_333, $sad['net_sen']);
        $this->assertTrue($sad['flags']['aggregate_at_or_above_om']);
        $this->assertFalse($sad['flags']['individual_at_or_above_om']);

        $this->actingAs($this->ctx['staff'])->get($this->base().'/audit-differences')->assertOk()->assertSee('Uncorrected misstatements by line item')->assertSee('at or above overall materiality');
        $this->actingAs($this->ctx['staff'])->get($this->base().'/findings')->assertOk()->assertSee('F03');
        $this->actingAs($this->ctx['staff'])->get($url)->assertOk()->assertSee('Findings from this sample')->assertSee('Evidence: confirmation-0001');
        $this->actingAs($this->ctx['staff'])->get($this->base()."/findings/{$projected->uuid}")->assertOk()->assertSee('Management considers');
    }

    public function test_validation_significance_approval_and_closing(): void
    {
        $b = $this->base();
        $this->actingAs($this->ctx['staff'])->post("{$b}/findings", ['type' => 'other', 'title' => 'x', 'description' => 'x'])->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings", ['type' => 'misstatement', 'title' => 'Cut-off', 'description' => 'Sales recorded early.'])
            ->assertSessionHasErrors(['misstatement_class', 'amount', 'engagement_fsli_id']);
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings", ['type' => 'control_deficiency', 'title' => 'No credit approval', 'description' => 'Credit limits overridden without approval.'])
            ->assertSessionHasErrors('deficiency_severity');
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings", ['type' => 'control_deficiency', 'title' => 'No credit approval', 'description' => 'Credit limits overridden without approval.',
            'deficiency_severity' => 'significant_deficiency', 'recommendation' => 'Enforce system approval.', 'is_significant' => '1'])->assertSessionHasNoErrors();
        $f = Finding::sole();
        $this->assertTrue($f->report_to_management, 'significant deficiencies are always communicated');
        $this->actingAs($this->ctx['senior'])->get("{$b}/findings/create?type=control_deficiency")->assertOk()->assertSee('Record a finding');
        $this->actingAs($this->ctx['senior'])->get("{$b}/findings/{$f->uuid}/edit")->assertOk()->assertSee('Edit finding F01');
        $this->actingAs($this->ctx['staff'])->get("{$b}/findings/{$f->uuid}/edit")->assertForbidden();

        // Significance: submitted by the senior, decided by the engagement partner only.
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$f->uuid}/submit", ['comment' => 'Override of credit limits'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->put("{$b}/findings/{$f->uuid}", ['type' => 'control_deficiency', 'title' => 'Changed', 'description' => 'x', 'deficiency_severity' => 'deficiency'])->assertSessionHasErrors('finding');
        $approval = $f->approvals()->pending()->sole();
        $this->assertSame('significant_finding', $approval->type->value);
        $this->decide($approval, $this->ctx['manager'])->assertForbidden();
        $this->decide($approval, $this->ctx['partner'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $f->fresh()->significance_status->value);

        // Changing an approved significant finding needs a fresh approval.
        $this->actingAs($this->ctx['senior'])->put("{$b}/findings/{$f->uuid}", ['type' => 'control_deficiency', 'title' => 'No credit approval', 'description' => 'Credit limits overridden on 14 occasions.',
            'deficiency_severity' => 'significant_deficiency', 'is_significant' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($f->fresh()->significance_status);
        $this->assertTrue($f->fresh()->needsApproval());

        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$f->uuid}/resolve", ['resolution' => 'Reported in the management letter.'])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $f->fresh()->status->value);
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$f->uuid}/withdraw", ['resolution' => 'x'])->assertSessionHasErrors('finding');

        // A proposed misstatement cannot be resolved until management responds; withdrawing is allowed.
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings", ['type' => 'misstatement', 'title' => 'Accrual', 'description' => 'Missing accrual.', 'engagement_fsli_id' => $this->fsliId('INV'), 'misstatement_class' => 'factual', 'amount' => '(500.00)'])->assertSessionHasNoErrors();
        $m = Finding::where('reference', 'F02')->sole();
        $this->assertSame(-50_000, $m->amount_sen);
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$m->uuid}/resolve", ['resolution' => 'Done'])->assertSessionHasErrors('resolution');
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$m->uuid}/withdraw", ['resolution' => 'Duplicate of client adjustment'])->assertSessionHasNoErrors();
        $this->assertTrue(app(AuditDifferences::class)->build($this->ctx['engagement'])['uncorrected']->isEmpty());

        // DB backstops: uncorrected without reason is refused.
        $this->expectException(QueryException::class);
        DB::table('findings')->where('id', $m->id)->update(['correction_status' => 'uncorrected', 'uncorrected_reason' => null]);
    }

    public function test_material_adjustments_need_partner_approval_and_corrected_findings_link_them(): void
    {
        $this->approvedMateriality(); // PM 5,625.00 is the default threshold
        $b = $this->base();
        $lines = fn ($amt) => [['account_code' => '1200', 'credit' => $amt], ['account_code' => '5000', 'debit' => $amt]];

        // Below PM: no approval needed, cannot be requested.
        $this->actingAs($this->ctx['staff'])->post("{$b}/adjustments", ['type' => 'audit', 'description' => 'Small write-down', 'lines' => $lines('500.00')])->assertSessionHasNoErrors();
        $small = Adjustment::latest('id')->first();
        $this->actingAs($this->ctx['staff'])->post("{$b}/adjustments/{$small->uuid}/approval")->assertSessionHasErrors('adjustment');
        $this->actingAs($this->ctx['manager'])->post("{$b}/adjustments/{$small->uuid}/post")->assertSessionHasNoErrors();

        // At or above PM: approval first; the approval binds the exact lines.
        $this->actingAs($this->ctx['staff'])->post("{$b}/adjustments", ['type' => 'audit', 'description' => 'NRV write-down', 'lines' => $lines('6,000.00')])->assertSessionHasNoErrors();
        $big = Adjustment::latest('id')->first();
        $this->actingAs($this->ctx['manager'])->post("{$b}/adjustments/{$big->uuid}/post")->assertSessionHasErrors('adjustment');
        $this->actingAs($this->ctx['staff'])->get("{$b}/adjustments/{$big->uuid}")->assertOk()->assertSee('Material adjustment')->assertSee('Request approval');
        $this->actingAs($this->ctx['staff'])->post("{$b}/adjustments/{$big->uuid}/approval", ['comment' => 'Per F01'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['staff'])->put("{$b}/adjustments/{$big->uuid}", ['description' => 'x', 'lines' => $lines('6,500.00')])->assertSessionHasErrors('adjustment');
        $approval = $big->approvals()->pending()->sole();
        $this->decide($approval, $this->ctx['manager'])->assertForbidden(); // engagement partner only
        $this->decide($approval, $this->ctx['partner'])->assertSessionHasNoErrors();

        // Editing after approval voids it.
        $this->actingAs($this->ctx['staff'])->put("{$b}/adjustments/{$big->uuid}", ['description' => 'NRV write-down revised', 'lines' => $lines('6,500.00')])->assertSessionHasNoErrors();
        $this->assertNull($big->fresh()->approval_status);
        $this->actingAs($this->ctx['manager'])->post("{$b}/adjustments/{$big->uuid}/post")->assertSessionHasErrors('adjustment');
        $this->actingAs($this->ctx['staff'])->post("{$b}/adjustments/{$big->uuid}/approval")->assertSessionHasNoErrors();
        $this->decide($big->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['manager'])->post("{$b}/adjustments/{$big->uuid}/post")->assertSessionHasNoErrors();
        $this->assertSame('posted', $big->fresh()->status->value);

        // A misstatement corrected by the posted adjustment leaves the uncorrected list.
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings", ['type' => 'misstatement', 'title' => 'Obsolete stock', 'description' => 'NRV below cost.', 'engagement_fsli_id' => $this->fsliId('INV'), 'misstatement_class' => 'factual', 'amount' => '6,500.00'])->assertSessionHasNoErrors();
        $f = Finding::sole();
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$f->uuid}/correction", ['correction_status' => 'corrected', 'adjustment' => $small->uuid])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post("{$b}/findings/{$f->uuid}/correction", ['correction_status' => 'corrected', 'adjustment' => $big->uuid])->assertSessionHasNoErrors();
        $sad = app(AuditDifferences::class)->build($this->ctx['engagement']);
        $this->assertSame(['F01'], $sad['corrected']->pluck('reference')->all());
        $this->assertSame(0, $sad['net_sen']);
        $this->actingAs($this->ctx['staff'])->get("{$b}/adjustments/{$big->uuid}")->assertOk()->assertSee('Corrects findings');
    }

    public function test_procedure_working_paper_is_opened_once(): void
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks/presumed')->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/programme', ['title' => 'Revenue cut-off', 'steps' => 'Test invoices either side of year end.', 'nature' => 'test_of_details',
            'timing' => 'final', 'risk_ids' => [\App\Models\Risk::where('reference', 'R01')->value('id')], 'assertions' => ['cut_off']])->assertSessionHasNoErrors();
        $p = Procedure::sole();
        $url = $this->base()."/programme/{$p->uuid}";
        $this->actingAs($this->ctx['staff'])->post("{$url}/paper")->assertRedirect();
        $wp = $p->fresh()->workingPaper;
        $this->assertSame(['F1', 'fieldwork'], [$wp->reference, $wp->section->value]);
        $this->assertStringContainsString('Test invoices', $wp->objective);
        $this->actingAs($this->ctx['staff'])->post("{$url}/paper")->assertRedirect();
        $this->assertSame(1, $this->ctx['engagement']->workingPapers()->where('section', 'fieldwork')->count());
        $this->actingAs($this->ctx['senior'])->get($url)->assertOk()->assertSee('F1')->assertSee('Record finding');
    }
}
