<?php

namespace Tests\Feature\Planning;

use App\Enums\SignificanceStatus;
use App\Models\Risk;
use App\Models\WorkingPaper;

class RiskTest extends PlanningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    private function risk(array $overrides = [], $user = null): Risk
    {
        $this->actingAs($user ?? $this->ctx['senior'])->post($this->base().'/planning/risks', $this->riskInput($overrides))->assertSessionHasNoErrors();

        return Risk::latest('id')->first();
    }

    public function test_recording_risks_with_validation_and_permissions(): void
    {
        $url = $this->base().'/planning/risks';
        $this->actingAs($this->ctx['senior'])->post($url, $this->riskInput(['assertions' => []]))->assertSessionHasErrors('assertions');
        $this->actingAs($this->ctx['senior'])->post($url, $this->riskInput(['assertions' => ['made_up']]))->assertSessionHasErrors('assertions.0');
        $this->actingAs($this->ctx['staff'])->post($url, $this->riskInput())->assertForbidden();

        $r = $this->risk();
        $this->assertSame('R01', $r->reference);
        $this->assertSame([$this->fsliId('TR')], $r->fslis->pluck('id')->all());
        $this->assertSame('high', $r->inherentRisk());   // medium + high
        $this->assertFalse($r->is_significant);

        $fs = $this->risk(['title' => 'Pressure to meet bank covenants', 'level' => 'financial_statement', 'fsli_ids' => [$this->fsliId('TR')], 'assertions' => ['existence']]);
        $this->assertSame('R02', $fs->reference);
        $this->assertSame([], $fs->assertions);   // FS-level risks carry no assertions
        $this->actingAs($this->ctx['staff'])->get($this->base().'/planning/risks')->assertOk()->assertSee('Overstatement of trade receivables');
    }

    public function test_significant_risk_approval_and_reapproval_after_substantive_change(): void
    {
        $r = $this->risk(['is_significant' => '1', 'planned_response' => 'Circularise debtors and test subsequent receipts.']);
        $this->assertTrue($r->needsApproval());

        $this->actingAs($this->ctx['partner'])->post($this->base()."/planning/risks/{$r->uuid}/submit")->assertSessionHasErrors('approval');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/risks/{$r->uuid}/submit")->assertSessionHasNoErrors();
        $this->assertSame(SignificanceStatus::Pending, $r->fresh()->significance_status);
        // Edits are blocked while the partner is deciding.
        $this->actingAs($this->ctx['senior'])->put($this->base()."/planning/risks/{$r->uuid}", $this->riskInput(['is_significant' => '1', 'planned_response' => 'x']))->assertSessionHasErrors('risk');

        $this->decide($r->approvals()->pending()->sole(), $this->ctx['manager'])->assertForbidden();
        $this->decide($r->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        $this->assertSame(SignificanceStatus::Approved, $r->fresh()->significance_status);

        // Non-substantive edit keeps approval; substantive edit needs approval again.
        $this->actingAs($this->ctx['senior'])->put($this->base()."/planning/risks/{$r->uuid}", $this->riskInput(['is_significant' => '1', 'planned_response' => 'Circularise debtors and test subsequent receipts.', 'controls_reliance' => '1', 'controls_description' => 'Credit control review']))->assertSessionHasNoErrors();
        $this->assertSame(SignificanceStatus::Approved, $r->fresh()->significance_status);
        $this->actingAs($this->ctx['senior'])->put($this->base()."/planning/risks/{$r->uuid}", $this->riskInput(['is_significant' => '1', 'likelihood' => 'high', 'planned_response' => 'Circularise debtors and test subsequent receipts.']))->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()->significance_status);
        $this->assertTrue($r->fresh()->needsApproval());
    }

    public function test_presumed_fraud_risks_rebuttal_and_withdrawal_rules(): void
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks/presumed')->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks/presumed')->assertSessionHasNoErrors(); // idempotent
        $this->assertSame(2, Risk::count());
        $override = Risk::where('presumed_key', 'management_override')->sole();
        $revenue = Risk::where('presumed_key', 'revenue_recognition')->sole();
        $this->assertTrue($override->is_significant && $override->is_fraud_risk);
        $this->assertSame([$this->fsliId('REV')], $revenue->fslis->pluck('id')->all());

        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/risks/{$override->uuid}/withdraw", ['reason' => 'Not relevant'])->assertSessionHasErrors('risk');
        $this->actingAs($this->ctx['senior'])->put($this->base()."/planning/risks/{$override->uuid}", ['title' => $override->title, 'description' => $override->description, 'level' => 'financial_statement', 'likelihood' => 'low', 'magnitude' => 'low', 'is_significant' => '0'])->assertSessionHasErrors('is_significant');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/risks/{$revenue->uuid}/withdraw", ['reason' => 'x'])->assertSessionHasErrors('risk');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/risks/{$revenue->uuid}/rebut", ['reason' => 'too short'])->assertSessionHasErrors('reason');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/risks/{$revenue->uuid}/rebut", ['reason' => 'Single fixed-fee contract with a government agency; no judgement in timing or amount of revenue.'])->assertSessionHasNoErrors();
        $revenue->refresh();
        $this->assertFalse($revenue->isActive());
        $this->assertTrue($revenue->rebutted());
        $this->assertFalse($revenue->is_significant);
    }

    public function test_risk_paper_gates_and_coverage_check(): void
    {
        $this->actingAs($this->ctx['senior'])->get($this->base().'/planning/risks')->assertOk()->assertSee('Approve materiality to see FSLIs');
        $wp = WorkingPaper::where('system_key', WorkingPaper::RISK_ASSESSMENT)->sole();
        $this->assertSame('C1', $wp->reference);
        $sign = fn () => $this->actingAs($this->ctx['senior'])->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer']);
        $sign()->assertSessionHasErrors('signoff');

        $this->approvedMateriality(); // PM = 5,625.00
        $gaps = app(\App\Services\Planning\RiskService::class)->coverageGaps($this->ctx['engagement']);
        $this->assertContains('REV', $gaps->pluck('fsli.code')->all());
        $this->assertContains('TR', $gaps->pluck('fsli.code')->all());

        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks/presumed');
        $this->risk();
        $sign()->assertSessionHasErrors('signoff'); // significant presumed risks not yet approved
        foreach (Risk::where('is_significant', true)->get() as $r) {
            $r->update(['planned_response' => $r->planned_response ?? 'Test cut-off and journals around year end.']);
            $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/risks/{$r->uuid}/submit")->assertSessionHasNoErrors();
            $this->decide($r->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        }
        $sign()->assertSessionHasNoErrors();
        $this->assertSame('prepared', $wp->fresh()->status->value);
        $this->assertNotContains('TR', app(\App\Services\Planning\RiskService::class)->coverageGaps($this->ctx['engagement'])->pluck('fsli.code')->all());

        // Any risk change invalidates the C1 sign-off.
        $this->risk(['title' => 'Inventory obsolescence', 'fsli_ids' => [$this->fsliId('INV')], 'assertions' => ['valuation_allocation']]);
        $this->assertSame('draft', $wp->fresh()->status->value);
    }
}
