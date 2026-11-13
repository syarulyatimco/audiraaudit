<?php

namespace App\Services\Planning;

use App\Enums\MaterialityStatus;
use App\Enums\WorkingPaperSection;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Services\WorkingPapers\WorkingPaperService;

/**
 * Planning working papers — A1 audit strategy, C1 risk assessment, D1 materiality. Their content is
 * the planning module's data, so their sign-offs attest to it (ADR-009) and are invalidated when it
 * changes. Each paper has sign-off gates in place of the free-text conclusion requirement.
 */
class PlanningPapers
{
    private const DEFINITIONS = [
        WorkingPaper::AUDIT_STRATEGY => ['A1', 'Overall audit strategy', WorkingPaperSection::Planning,
            'Establish the overall audit strategy that sets the scope, timing and direction of the audit and guides the audit plan.'],
        WorkingPaper::RISK_ASSESSMENT => ['C1', 'Identification and assessment of risks of material misstatement', WorkingPaperSection::RiskAssessment,
            'Identify and assess the risks of material misstatement at the financial statement and assertion levels, including significant and fraud risks.'],
        WorkingPaper::AUDIT_PROGRAMME => ['A2', 'Audit programme', WorkingPaperSection::Planning,
            'Design further audit procedures whose nature, timing and extent respond to the assessed risks of material misstatement.'],
        WorkingPaper::MATERIALITY => ['D1', 'Materiality', WorkingPaperSection::Materiality,
            'Determine overall materiality, performance materiality, the clearly trivial threshold and any specific materiality, with the rationale for each.'],
    ];

    public function __construct(private WorkingPaperService $papers) {}

    public function find(Engagement $engagement, string $key): ?WorkingPaper
    {
        return $engagement->workingPapers()->where('system_key', $key)->first();
    }

    /** The planning paper for $key, created on first use. */
    public function paper(Engagement $engagement, string $key, User $actor): WorkingPaper
    {
        if ($existing = $this->find($engagement, $key)) {
            return $existing;
        }
        [$ref, $title, $section, $objective] = self::DEFINITIONS[$key];
        if ($engagement->workingPapers()->where('reference', $ref)->exists()) {
            $ref .= '.0';
        }

        return $this->papers->create($engagement, [
            'reference' => $ref, 'title' => $title, 'section' => $section->value, 'system_key' => $key, 'objective' => $objective,
        ], $actor);
    }

    /** Re-hash planning papers after their underlying data changed (invalidating stale sign-offs). */
    public function refresh(Engagement $engagement, User $actor, string $reason, array $keys = WorkingPaper::PLANNING_KEYS): void
    {
        foreach ($keys as $key) {
            if ($wp = $this->find($engagement, $key)) {
                $this->papers->contentChanged($wp, $actor, $reason);
            }
        }
    }

    /** What a planning paper's sign-offs attest to (merged into WorkingPaperService::hash). */
    public function hashParts(WorkingPaper $wp): array
    {
        $e = $wp->engagement;

        return match ($wp->system_key) {
            WorkingPaper::MATERIALITY => ['materiality' => $this->materialityFingerprint($e)],
            WorkingPaper::RISK_ASSESSMENT => ['risks' => $this->riskFingerprint($e)],
            WorkingPaper::AUDIT_PROGRAMME => ['procedures' => $e->procedures()->with('risks')->get()->map(fn ($p) => [
                $p->reference, $p->title, $p->steps, $p->nature->value, $p->timing->value, $p->assertions, $p->engagement_fsli_id,
                $p->assigned_to, $p->budget_hours, $p->risks->pluck('id')->sort()->values()->all(), $p->withdrawn_at?->toIso8601String(),
            ])->all()],
            WorkingPaper::AUDIT_STRATEGY => [
                'strategy' => ($s = $e->auditStrategy()->first()) ? [
                    $s->sections, $s->controls_approach, $s->planning_meeting_on?->toDateString(), $s->fieldwork_start_on?->toDateString(),
                    $s->fieldwork_end_on?->toDateString(), $s->report_target_on?->toDateString(),
                ] : null,
                'materiality' => $e->currentMateriality()?->id,
                'significant' => $e->risks()->active()->where('is_significant', true)->get()->map(fn (Risk $r) => [$r->reference, $r->significance_status?->value])->all(),
            ],
            default => [],
        };
    }

    /**
     * Reasons the preparer cannot sign this planning paper yet (empty = may sign).
     *
     * @return list<string>
     */
    public function gates(WorkingPaper $wp): array
    {
        $e = $wp->engagement;
        $out = [];
        $approved = $e->currentMateriality();
        $pending = $e->materialityAssessments()->where('status', MaterialityStatus::PendingApproval->value)->exists();
        $unapproved = $e->risks()->active()->get()->filter->needsApproval();

        if ($wp->system_key === WorkingPaper::MATERIALITY) {
            if (! $approved) {
                $out[] = 'Materiality has not been approved by the engagement partner.';
            }
            if ($pending) {
                $out[] = 'A materiality version is awaiting approval.';
            }
        }
        if ($wp->system_key === WorkingPaper::RISK_ASSESSMENT) {
            $risks = $e->risks()->get();
            if ($risks->whereNull('withdrawn_at')->isEmpty()) {
                $out[] = 'No risks of material misstatement have been recorded.';
            }
            $override = $risks->firstWhere('presumed_key', 'management_override');
            if (! $override || ! $override->isActive()) {
                $out[] = 'The presumed risk of management override of controls has not been recorded.';
            }
            $revenue = $risks->firstWhere('presumed_key', 'revenue_recognition');
            if (! $revenue || (! $revenue->isActive() && ! $revenue->rebutted())) {
                $out[] = 'The presumed fraud risk in revenue recognition has been neither recorded nor rebutted.';
            }
            foreach ($unapproved as $r) {
                $out[] = "Significant risk {$r->reference} is not approved by the engagement partner.";
            }
        }
        if ($wp->system_key === WorkingPaper::AUDIT_PROGRAMME) {
            $procedures = $e->procedures()->active()->with('risks')->get();
            if ($procedures->isEmpty()) {
                $out[] = 'The programme has no procedures.';
            }
            foreach (app(\App\Services\Programme\ProgrammeService::class)->uncoveredRisks($e) as $r) {
                $out[] = "Risk {$r->reference} has no responding procedure.";
            }
            foreach ($e->risks()->active()->where('is_significant', true)->get() as $r) {
                $substantive = $procedures->filter(fn ($p) => $p->risks->contains('id', $r->id) && $p->nature !== \App\Enums\ProcedureNature::TestOfControls);
                if ($substantive->isEmpty()) {
                    $out[] = "Significant risk {$r->reference} needs at least one substantive procedure.";
                }
            }
            foreach ($procedures->whereNull('assigned_to') as $p) {
                $out[] = "Procedure {$p->reference} is not assigned to anyone.";
            }
        }
        if ($wp->system_key === WorkingPaper::AUDIT_STRATEGY) {
            $s = $e->auditStrategy()->first();
            foreach (config('audira.audit_strategy.required') as $key) {
                if (blank($s?->sections[$key] ?? null)) {
                    $out[] = 'Complete the strategy section "'.config("audira.audit_strategy.sections.{$key}.0").'".';
                }
            }
            if (! $approved) {
                $out[] = 'The strategy needs approved materiality.';
            }
            if ($unapproved->isNotEmpty()) {
                $out[] = 'All significant risks must be approved before the strategy is signed off.';
            }
        }

        return $out;
    }

    private function materialityFingerprint(Engagement $e): ?array
    {
        $m = $e->currentMateriality();

        return $m ? [
            $m->id, $m->version, $m->benchmark->value, $m->benchmark_amount_sen, $m->om_sen, $m->pm_sen, $m->ct_sen,
            $m->specifics()->get()->map(fn ($s) => [$s->area, $s->engagement_fsli_id, $s->amount_sen])->all(),
        ] : null;
    }

    private function riskFingerprint(Engagement $e): array
    {
        return $e->risks()->with('fslis')->get()->map(fn (Risk $r) => [
            $r->reference, $r->title, $r->description, $r->level->value, $r->assertions, $r->inherent_factors,
            $r->likelihood->value, $r->magnitude->value, $r->is_significant, $r->significance_status?->value, $r->is_fraud_risk,
            $r->presumed_key, $r->rebuttal_reason, $r->controls_reliance, $r->controls_description, $r->planned_response,
            $r->fslis->pluck('id')->sort()->values()->all(), $r->withdrawn_at?->toIso8601String(),
        ])->all();
    }
}
