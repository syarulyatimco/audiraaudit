<?php

namespace Tests\Feature\Planning;

use App\Models\Approval;
use App\Models\LibraryFramework;
use App\Models\LibraryParagraph;
use App\Models\LibraryStandard;
use App\Models\LibraryVersion;
use App\Models\MaterialityAssessment;
use App\Models\User;
use Tests\Feature\TrialBalance\TrialBalanceTestCase;

/** TB fixture (mapped): revenue 296,000; cost of sales 120,000; admin 26,000 → PBT 150,000; total assets 305,000. */
abstract class PlanningTestCase extends TrialBalanceTestCase
{
    protected function materialityInput(array $overrides = []): array
    {
        return array_merge([
            'benchmark' => 'profit_before_tax', 'benchmark_basis' => 'current_year',
            'om_percentage' => '5', 'pm_percentage' => '75', 'ct_percentage' => '5',
            'rationale' => 'Profit before tax is the focus of the shareholders of this profit-oriented company.',
        ], $overrides);
    }

    protected function draftMateriality(array $overrides = [], $user = null): MaterialityAssessment
    {
        $this->actingAs($user ?? $this->ctx['senior'])->put($this->base().'/planning/materiality', $this->materialityInput($overrides))->assertSessionHasNoErrors();

        return $this->ctx['engagement']->materialityAssessments()->first();
    }

    /** Draft → submitted by the senior → approved by the engagement partner. */
    protected function approvedMateriality(array $overrides = []): MaterialityAssessment
    {
        $m = $this->draftMateriality($overrides);
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/materiality/{$m->uuid}/submit")->assertSessionHasNoErrors();
        $this->decide($m->approvals()->pending()->sole(), $this->ctx['partner']);

        return $m->fresh();
    }

    protected function decide(Approval $a, User $by, bool $approve = true, ?string $comment = null)
    {
        return $this->actingAs($by)->post("/approvals/{$a->uuid}/decide", ['decision' => $approve ? 'approve' : 'reject', 'decision_comment' => $comment]);
    }

    protected function riskInput(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Overstatement of trade receivables', 'description' => 'Debtor balances may not be recoverable given slow collections.',
            'level' => 'assertion', 'fsli_ids' => [$this->fsliId('TR')], 'assertions' => ['valuation_allocation', 'existence'],
            'inherent_factors' => ['subjectivity', 'uncertainty'], 'likelihood' => 'medium', 'magnitude' => 'high',
        ], $overrides);
    }

    /** A published library version with synthetic paragraphs (no real standard text). */
    protected function publishedParagraphs(): array
    {
        $sa = User::factory()->superadmin()->create();
        $fw = LibraryFramework::firstOrCreate(['code' => 'test'], ['name' => 'Test framework', 'category' => 'auditing']);
        $st = LibraryStandard::firstOrCreate(['framework_id' => $fw->id, 'reference' => 'TS 320'], ['title' => 'Synthetic standard on materiality']);
        $v = LibraryVersion::forceCreate(['standard_id' => $st->id, 'version_label' => 'Synthetic 1', 'status' => 'draft', 'source_title' => 'Synthetic', 'source_url' => 'https://example.org', 'created_by' => $sa->id]);
        $a = LibraryParagraph::forceCreate(['version_id' => $v->id, 'paragraph_ref' => '10', 'heading' => 'Benchmarks', 'body' => 'Synthetic text about choosing a materiality benchmark for an entity.']);
        $b = LibraryParagraph::forceCreate(['version_id' => $v->id, 'paragraph_ref' => '11', 'heading' => 'Performance materiality', 'body' => 'Synthetic text about performance materiality.']);
        $v->forceFill(['status' => 'published', 'published_by' => $sa->id, 'published_at' => now()])->save();

        return [$a, $b];
    }
}
