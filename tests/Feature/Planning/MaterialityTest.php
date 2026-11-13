<?php

namespace Tests\Feature\Planning;

use App\Enums\FirmRole;
use App\Enums\MaterialityStatus;
use App\Models\ActivityLog;
use App\Models\MaterialityAssessment;
use App\Models\WorkingPaper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MaterialityTest extends PlanningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    public function test_amounts_are_computed_from_the_tb_not_the_browser(): void
    {
        $m = $this->draftMateriality(['benchmark_amount' => '999999999', 'om_sen' => 1]);

        $this->assertSame(MaterialityStatus::Draft, $m->status);
        $this->assertSame(15_000_000, $m->benchmark_amount_sen);      // PBT 150,000.00
        $this->assertSame(750_000, $m->om_sen);                      // 5%
        $this->assertSame(562_500, $m->pm_sen);                      // 75% of overall
        $this->assertSame(37_500, $m->ct_sen);                       // 5% of overall
        $this->assertSame(29_600_000, $m->fs_snapshot['current']['revenue']);
        $this->assertSame(30_500_000, $m->fs_snapshot['current']['total_assets']);
        $this->assertSame(14_600_000, $m->fs_snapshot['current']['total_expenses']);

        $this->actingAs($this->ctx['senior'])->get($this->base().'/planning/materiality')->assertOk()
            ->assertSee('no standard prescribes a fixed percentage')->assertSee('Save draft');
    }

    public function test_validation_of_percentages_bases_and_specific_materiality(): void
    {
        $url = $this->base().'/planning/materiality';
        $this->actingAs($this->ctx['senior'])->put($url, $this->materialityInput(['ct_percentage' => '80']))->assertSessionHasErrors('ct_percentage');
        $this->actingAs($this->ctx['senior'])->put($url, $this->materialityInput(['benchmark_basis' => 'budget', 'benchmark_amount' => '200,000']))->assertSessionHasErrors('benchmark_reason');
        $this->actingAs($this->ctx['senior'])->put($url, $this->materialityInput(['specifics' => [['area' => 'Directors remuneration', 'amount' => '8000', 'rationale' => 'Sensitive']]]))->assertSessionHasErrors('specifics.0.amount');
        $this->assertSame(0, MaterialityAssessment::count());

        $m = $this->draftMateriality(['benchmark_basis' => 'budget', 'benchmark_amount' => '200,000.00', 'benchmark_reason' => 'Board-approved budget', 'specifics' => [
            ['area' => 'Directors remuneration', 'engagement_fsli_id' => $this->fsliId('ADMIN'), 'amount' => '1,000', 'rationale' => 'Sensitive disclosure'],
        ]]);
        $this->assertSame(20_000_000, $m->benchmark_amount_sen);
        $this->assertSame(100_000, $m->specifics()->sole()->amount_sen);

        $this->actingAs($this->ctx['staff'])->put($url, $this->materialityInput())->assertForbidden(); // staff cannot prepare materiality
    }

    public function test_engagement_partner_approves_with_segregation_of_duties(): void
    {
        $m = $this->draftMateriality();
        // The engagement partner cannot submit what only they may approve.
        $this->actingAs($this->ctx['partner'])->post($this->base()."/planning/materiality/{$m->uuid}/submit")->assertSessionHasErrors('approval');

        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/materiality/{$m->uuid}/submit", ['comment' => 'Please approve'])->assertSessionHasNoErrors();
        $approval = $m->approvals()->pending()->sole();
        $this->assertSame($this->ctx['engagement']->id, $approval->engagement_id);
        $this->assertSame(MaterialityStatus::PendingApproval, $m->fresh()->status);

        // Manager (not the engagement partner) and an outside firm partner cannot decide.
        $this->decide($approval, $this->ctx['manager'])->assertForbidden();
        $other = $this->staff($this->ctx['firm'], FirmRole::Partner);
        $this->decide($approval, $other)->assertForbidden();
        $this->actingAs($this->ctx['senior'])->put($this->base().'/planning/materiality', $this->materialityInput())->assertSessionHasErrors('materiality');

        $this->decide($approval, $this->ctx['partner'], false)->assertSessionHasErrors('decision_comment');
        $this->decide($approval, $this->ctx['partner'], false, 'Explain why not revenue')->assertSessionHasNoErrors();
        $this->assertSame(MaterialityStatus::Rejected, $m->fresh()->status);

        $this->draftMateriality(['rationale' => 'Revenue considered; PBT remains the focus.']);
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/materiality/{$m->uuid}/submit")->assertSessionHasNoErrors();
        $this->decide($m->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        $m->refresh();
        $this->assertSame(MaterialityStatus::Approved, $m->status);
        $this->assertTrue($this->ctx['engagement']->currentMateriality()->is($m));
        $this->assertTrue(ActivityLog::where('event', 'approval.approved')->exists());
    }

    public function test_approved_versions_are_frozen_and_revisions_supersede_them(): void
    {
        $v1 = $this->approvedMateriality();
        $this->assertSame(MaterialityStatus::Approved, $v1->status);

        try {
            DB::transaction(fn () => DB::table('materiality_assessments')->where('id', $v1->id)->update(['om_sen' => 1])); // savepoint
            $this->fail('Approved materiality must be immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/materiality/revise', ['stage' => 'revised', 'revision_reason' => 'Draft accounts adjusted'])->assertSessionHasNoErrors();
        $v2 = $this->ctx['engagement']->materialityAssessments()->where('version', 2)->sole();
        $this->assertSame(MaterialityStatus::Draft, $v2->status);
        $this->assertTrue($this->ctx['engagement']->currentMateriality()->is($v1), 'v1 stays in force until v2 is approved');

        $this->draftMateriality(['om_percentage' => '4', 'revision_reason' => 'Lower percentage after control findings', 'stage' => 'revised']);
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/materiality/{$v2->uuid}/submit")->assertSessionHasNoErrors();
        $this->decide($v2->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();

        $this->assertSame(MaterialityStatus::Superseded, $v1->fresh()->status);
        $this->assertSame(600_000, $this->ctx['engagement']->currentMateriality()->om_sen);
        $this->assertSame(1, MaterialityAssessment::where('status', 'approved')->count());
    }

    public function test_materiality_paper_gates_and_invalidation(): void
    {
        $this->actingAs($this->ctx['senior'])->get($this->base().'/planning/materiality')->assertOk();
        $wp = WorkingPaper::where('system_key', WorkingPaper::MATERIALITY)->sole();
        $this->assertSame('D1', $wp->reference);
        $this->actingAs($this->ctx['senior'])->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer'])->assertSessionHasErrors('signoff');

        $this->approvedMateriality();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer'])->assertSessionHasNoErrors();
        $this->assertSame('prepared', $wp->fresh()->status->value);

        // A newly approved version invalidates the sign-off on D1.
        $this->actingAs($this->ctx['manager'])->post($this->base().'/planning/materiality/revise', ['stage' => 'final', 'revision_reason' => 'Final figures'])->assertSessionHasNoErrors();
        $v2 = $this->ctx['engagement']->materialityAssessments()->where('version', 2)->sole();
        $this->actingAs($this->ctx['manager'])->post($this->base()."/planning/materiality/{$v2->uuid}/submit")->assertSessionHasNoErrors();
        $this->decide($v2->approvals()->pending()->sole(), $this->ctx['partner']);
        $this->assertSame('draft', $wp->fresh()->status->value);
        $this->assertNotNull($wp->signoffs()->first()->invalidated_at);
    }

    public function test_engagement_approvals_are_hidden_from_users_outside_the_engagement(): void
    {
        $m = $this->draftMateriality();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/materiality/{$m->uuid}/submit")->assertSessionHasNoErrors();

        $outsider = $this->staff($this->ctx['firm'], FirmRole::Manager);
        $this->actingAs($outsider)->get('/approvals')->assertOk()->assertDontSee('Materiality v1');
        $this->actingAs($this->ctx['partner'])->get('/approvals')->assertOk()->assertSee('Materiality v1');
        $this->actingAs($outsider)->get($this->base().'/planning/materiality')->assertForbidden();

        [, $foreign] = $this->makeFirm();
        $this->actingAs($foreign)->get($this->base().'/planning/materiality')->assertNotFound();
    }
}
