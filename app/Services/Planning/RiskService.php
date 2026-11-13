<?php

namespace App\Services\Planning;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\Assertion;
use App\Enums\FsliClassification;
use App\Enums\InherentRiskFactor;
use App\Enums\RiskLevel;
use App\Enums\RiskRating;
use App\Enums\SignificanceStatus;
use App\Models\AiRun;
use App\Models\Approval;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Services\Approvals\ApprovalService;
use App\Services\TrialBalance\LeadScheduleService;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Risk register (ISA 315 / ISA 240 concepts). Inherent risk is assessed from likelihood and
 * magnitude with the inherent risk factors that drive it; whether a risk is significant is the
 * team's judgement and needs the engagement partner's approval. Risks are withdrawn, never
 * deleted, so the assessment history stays in the file.
 */
class RiskService
{
    /** Fields whose change means an approved significant risk must be approved again. */
    private const SUBSTANTIVE = ['title', 'description', 'level', 'assertions', 'inherent_factors', 'likelihood', 'magnitude', 'is_fraud_risk', 'planned_response'];

    public function __construct(
        private ApprovalService $approvals,
        private PlanningPapers $papers,
        private LeadScheduleService $schedules,
        private ActivityLogger $logger,
    ) {}

    /** @param array<string, mixed> $data validated input (see validate()) */
    public function create(Engagement $engagement, array $data, User $actor, string $source = 'manual', ?AiRun $aiRun = null): Risk
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement);
        $clean = $this->validate($engagement, $data);

        return DB::transaction(function () use ($engagement, $clean, $actor, $source, $aiRun) {
            Engagement::whereKey($engagement->id)->lockForUpdate()->first();
            $risk = $engagement->risks()->create($clean['attributes'] + [
                'reference' => $this->nextReference($engagement),
                'source' => $source,
                'ai_run_id' => $aiRun?->id,
                'presumed_key' => $clean['presumed_key'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->syncFslis($risk, $clean['fsli_ids']);
            $this->logger->log('risk.created', "Risk {$risk->reference} {$risk->title} recorded", $risk, ['source' => $source, 'significant' => $risk->is_significant], $actor);
            $this->papers->refresh($engagement, $actor, "Risk {$risk->reference} added", [WorkingPaper::RISK_ASSESSMENT, WorkingPaper::AUDIT_STRATEGY]);

            return $risk;
        });
    }

    public function update(Risk $risk, array $data, User $actor): Risk
    {
        EngagementGuard::assertEditable($risk->engagement);
        $this->authorise($actor, $risk->engagement);
        $this->assertChangeable($risk);
        $clean = $this->validate($risk->engagement, $data, $risk);

        return DB::transaction(function () use ($risk, $clean, $actor) {
            $beforeFslis = $risk->fslis()->pluck('engagement_fsli_id')->sort()->values()->all();
            $risk->fill($clean['attributes'] + ['updated_by' => $actor->id]);
            $fsliChanged = $beforeFslis !== collect($clean['fsli_ids'])->sort()->values()->all();
            $substantive = $fsliChanged || array_intersect(array_keys($risk->getDirty()), self::SUBSTANTIVE) !== [];

            if (! $risk->is_significant) {
                $risk->significance_status = null;
            } elseif ($risk->significance_status === SignificanceStatus::Approved && $substantive) {
                $risk->significance_status = null; // must be approved again
                $this->logger->log('risk.reapproval_required', "Significant risk {$risk->reference} changed after approval; partner approval required again", $risk, [], $actor);
            }
            $risk->save();
            $this->syncFslis($risk, $clean['fsli_ids']);
            $this->logger->log('risk.updated', "Risk {$risk->reference} updated", $risk, ['fields' => array_keys($risk->getChanges())], $actor);
            $this->papers->refresh($risk->engagement, $actor, "Risk {$risk->reference} updated", [WorkingPaper::RISK_ASSESSMENT, WorkingPaper::AUDIT_STRATEGY]);

            return $risk->fresh('fslis');
        });
    }

    public function submitSignificance(Risk $risk, User $actor, ?string $comment): Approval
    {
        EngagementGuard::assertEditable($risk->engagement);
        $this->authorise($actor, $risk->engagement);
        if (! $risk->isActive() || ! $risk->is_significant) {
            throw ValidationException::withMessages(['risk' => 'Only an active significant risk is submitted for approval.']);
        }
        if (in_array($risk->significance_status, [SignificanceStatus::Pending, SignificanceStatus::Approved], true)) {
            throw ValidationException::withMessages(['risk' => 'This risk is already approved or awaiting approval.']);
        }

        return DB::transaction(function () use ($risk, $actor, $comment) {
            $approval = $this->approvals->request($risk, ApprovalType::SignificantRisk, $actor, $comment, [
                'reference' => $risk->reference, 'title' => $risk->title, 'level' => $risk->level->value,
                'assertions' => $risk->assertions, 'fslis' => $risk->fslis()->pluck('code')->all(),
                'likelihood' => $risk->likelihood->value, 'magnitude' => $risk->magnitude->value, 'fraud' => $risk->is_fraud_risk,
                'planned_response' => $risk->planned_response,
            ]);
            $risk->forceFill(['significance_status' => SignificanceStatus::Pending])->save();

            return $approval;
        });
    }

    /** Called by the approvals engine inside the decision transaction. */
    public function applyDecision(Risk $risk, Approval $approval): void
    {
        $risk->forceFill(['significance_status' => match ($approval->status) {
            ApprovalStatus::Approved => SignificanceStatus::Approved,
            ApprovalStatus::Rejected => SignificanceStatus::Rejected,
            default => null,
        }])->save();
        $actor = User::find($approval->decided_by ?? $approval->requested_by);
        $this->papers->refresh($risk->engagement, $actor, "Significant risk {$risk->reference} {$approval->status->value}", [WorkingPaper::RISK_ASSESSMENT, WorkingPaper::AUDIT_STRATEGY]);
    }

    public function withdraw(Risk $risk, User $actor, string $reason): void
    {
        EngagementGuard::assertEditable($risk->engagement);
        $this->authorise($actor, $risk->engagement);
        $this->assertChangeable($risk);
        if ($risk->presumed_key === 'management_override') {
            throw ValidationException::withMessages(['risk' => 'The risk of management override of controls is present in every audit and cannot be withdrawn.']);
        }
        if ($risk->presumed_key === 'revenue_recognition') {
            throw ValidationException::withMessages(['risk' => 'Use "Rebut presumption" to document why fraud in revenue recognition is not a risk.']);
        }
        $this->retire($risk, $actor, $reason, "Risk {$risk->reference} withdrawn");
    }

    /** Rebut the presumed fraud risk in revenue recognition, with documented reasons. */
    public function rebut(Risk $risk, User $actor, string $reason): void
    {
        EngagementGuard::assertEditable($risk->engagement);
        $this->authorise($actor, $risk->engagement);
        $this->assertChangeable($risk);
        if ($risk->presumed_key !== 'revenue_recognition') {
            throw ValidationException::withMessages(['risk' => 'Only the presumed fraud risk in revenue recognition can be rebutted.']);
        }
        DB::transaction(function () use ($risk, $actor, $reason) {
            $risk->forceFill(['rebuttal_reason' => $reason, 'is_significant' => false, 'significance_status' => null])->save();
            $this->retire($risk, $actor, 'Presumption rebutted: '.$reason, "Presumed revenue fraud risk {$risk->reference} rebutted");
        });
    }

    /** Record the two presumed fraud risks if they are not yet in the register. */
    public function addPresumedRisks(Engagement $engagement, User $actor): int
    {
        $existing = $engagement->risks()->whereNotNull('presumed_key')->pluck('presumed_key')->all();
        $revenue = $engagement->fslis()->where('classification', FsliClassification::Revenue->value)->pluck('id')->all();
        $created = 0;

        if (! in_array('revenue_recognition', $existing, true)) {
            $this->create($engagement, [
                'title' => 'Fraud in revenue recognition (presumed)',
                'description' => 'Presumed risk of fraud in revenue recognition. Identify which revenue types, transactions or assertions give rise to it, or rebut the presumption with documented reasons.',
                'level' => $revenue ? RiskLevel::Assertion->value : RiskLevel::FinancialStatement->value,
                'fsli_ids' => $revenue,
                'assertions' => $revenue ? [Assertion::Occurrence->value, Assertion::CutOff->value] : [],
                'inherent_factors' => [InherentRiskFactor::BiasOrFraud->value],
                'likelihood' => RiskRating::Medium->value, 'magnitude' => RiskRating::High->value,
                'is_significant' => true, 'is_fraud_risk' => true, 'presumed_key' => 'revenue_recognition',
            ], $actor, 'presumed');
            $created++;
        }
        if (! in_array('management_override', $existing, true)) {
            $this->create($engagement, [
                'title' => 'Management override of controls',
                'description' => 'Management is in a unique position to perpetrate fraud by overriding controls. This risk is present in all entities.',
                'level' => RiskLevel::FinancialStatement->value, 'fsli_ids' => [], 'assertions' => [],
                'inherent_factors' => [InherentRiskFactor::BiasOrFraud->value],
                'likelihood' => RiskRating::Medium->value, 'magnitude' => RiskRating::High->value,
                'is_significant' => true, 'is_fraud_risk' => true, 'presumed_key' => 'management_override',
                'planned_response' => 'Test journal entries and other adjustments, review accounting estimates for bias, and evaluate the business rationale of significant unusual transactions.',
            ], $actor, 'presumed');
            $created++;
        }

        return $created;
    }

    /**
     * FSLIs whose adjusted balance is at or above performance materiality but have no active
     * assertion-level risk linked — a completeness prompt for the team, not a conclusion.
     *
     * @return Collection<int, array{fsli: \App\Models\EngagementFsli, amount: int}>
     */
    public function coverageGaps(Engagement $engagement): Collection
    {
        $pm = $engagement->currentMateriality()?->pm_sen;
        if (! $pm) {
            return collect();
        }
        $linked = DB::table('risk_fslis')->join('risks', 'risks.id', '=', 'risk_fslis.risk_id')
            ->where('risks.engagement_id', $engagement->id)->whereNull('risks.withdrawn_at')
            ->pluck('risk_fslis.engagement_fsli_id')->unique()->all();

        return collect($this->schedules->build($engagement)['fslis'])
            ->filter(fn ($s) => abs($s['cy_adj']) >= $pm && ! in_array($s['fsli']->id, $linked, true))
            ->map(fn ($s) => ['fsli' => $s['fsli'], 'amount' => (int) $s['cy_adj']])->values();
    }

    /**
     * @return array{attributes: array<string, mixed>, fsli_ids: list<int>, presumed_key?: ?string}
     */
    private function validate(Engagement $engagement, array $data, ?Risk $existing = null): array
    {
        $level = RiskLevel::tryFrom((string) ($data['level'] ?? '')) ?? throw ValidationException::withMessages(['level' => 'Choose the level of the risk.']);
        $assertions = array_values(array_unique(array_map('strval', (array) ($data['assertions'] ?? []))));
        $factors = array_values(array_unique(array_map('strval', (array) ($data['inherent_factors'] ?? []))));
        $fsliIds = array_values(array_unique(array_map('intval', (array) ($data['fsli_ids'] ?? []))));

        if (array_diff($assertions, Assertion::values())) {
            throw ValidationException::withMessages(['assertions' => 'Unknown assertion.']);
        }
        if (array_diff($factors, InherentRiskFactor::values())) {
            throw ValidationException::withMessages(['inherent_factors' => 'Unknown inherent risk factor.']);
        }
        $known = $engagement->fslis()->pluck('id')->all();
        if (array_diff($fsliIds, $known)) {
            throw ValidationException::withMessages(['fsli_ids' => 'Unknown FSLI.']);
        }
        if ($level === RiskLevel::Assertion && (! $fsliIds || ! $assertions)) {
            throw ValidationException::withMessages(['assertions' => 'An assertion-level risk needs at least one FSLI and one assertion.']);
        }
        if (blank($data['title'] ?? null) || blank($data['description'] ?? null)) {
            throw ValidationException::withMessages(['title' => 'A title and description are required.']);
        }
        $likelihood = RiskRating::tryFrom((string) ($data['likelihood'] ?? '')) ?? throw ValidationException::withMessages(['likelihood' => 'Assess likelihood.']);
        $magnitude = RiskRating::tryFrom((string) ($data['magnitude'] ?? '')) ?? throw ValidationException::withMessages(['magnitude' => 'Assess magnitude.']);
        $significant = (bool) ($data['is_significant'] ?? false);
        if ($existing?->presumed_key === 'management_override' && ! $significant) {
            throw ValidationException::withMessages(['is_significant' => 'Management override of controls is always treated as a significant risk.']);
        }
        if ($significant && blank($data['planned_response'] ?? null) && $existing) {
            throw ValidationException::withMessages(['planned_response' => 'Describe the planned response to a significant risk.']);
        }
        $reliance = (bool) ($data['controls_reliance'] ?? false);
        if ($reliance && blank($data['controls_description'] ?? null)) {
            throw ValidationException::withMessages(['controls_description' => 'Describe the controls you plan to rely on.']);
        }

        return [
            'attributes' => [
                'title' => trim($data['title']), 'description' => trim($data['description']), 'level' => $level,
                'assertions' => $level === RiskLevel::Assertion ? $assertions : [],
                'inherent_factors' => $factors, 'likelihood' => $likelihood, 'magnitude' => $magnitude,
                'is_significant' => $significant, 'is_fraud_risk' => (bool) ($data['is_fraud_risk'] ?? false),
                'controls_reliance' => $reliance, 'controls_description' => $reliance ? trim($data['controls_description']) : null,
                'planned_response' => filled($data['planned_response'] ?? null) ? trim($data['planned_response']) : null,
            ],
            'fsli_ids' => $fsliIds,
            'presumed_key' => $data['presumed_key'] ?? null,
        ];
    }

    private function syncFslis(Risk $risk, array $ids): void
    {
        $risk->fslis()->sync(collect($ids)->mapWithKeys(fn ($id) => [$id => ['firm_id' => $risk->firm_id]])->all());
    }

    private function retire(Risk $risk, User $actor, string $reason, string $description): void
    {
        DB::transaction(function () use ($risk, $actor, $reason, $description) {
            $risk->forceFill(['withdrawn_at' => now(), 'withdrawn_reason' => $reason, 'updated_by' => $actor->id])->save();
            $this->logger->log('risk.withdrawn', $description, $risk, ['reason' => $reason], $actor);
            $this->papers->refresh($risk->engagement, $actor, $description, [WorkingPaper::RISK_ASSESSMENT, WorkingPaper::AUDIT_STRATEGY]);
        });
    }

    private function assertChangeable(Risk $risk): void
    {
        if (! $risk->isActive()) {
            throw ValidationException::withMessages(['risk' => 'This risk has been withdrawn.']);
        }
        if ($risk->significance_status === SignificanceStatus::Pending) {
            throw ValidationException::withMessages(['risk' => 'This risk is awaiting partner approval. Withdraw the request before changing it.']);
        }
    }

    private function nextReference(Engagement $engagement): string
    {
        $n = $engagement->risks()->count() + 1;
        do {
            $ref = sprintf('R%02d', $n++);
        } while ($engagement->risks()->where('reference', $ref)->exists());

        return $ref;
    }

    private function authorise(User $actor, Engagement $engagement): void
    {
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.manage_risks')) {
            throw new AuthorizationException('Your role on this engagement does not allow changing the risk assessment.');
        }
    }
}
