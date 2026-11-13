<?php

namespace App\Services\Planning;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\BenchmarkBasis;
use App\Enums\MaterialityBenchmark;
use App\Enums\MaterialityStage;
use App\Enums\MaterialityStatus;
use App\Models\AiRun;
use App\Models\Approval;
use App\Models\Engagement;
use App\Models\MaterialityAssessment;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Services\Approvals\ApprovalService;
use App\Services\TrialBalance\LeadScheduleService;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Materiality (CLAUDE.md §6). Benchmarks come from AUDIRA's own lead schedules; percentages are the
 * team's professional judgement (no percentage is presented as an ISA requirement). Amounts are
 * computed here, never accepted from the browser. Approval by the engagement partner goes through
 * the approvals engine; approved versions are frozen and revised by creating a new version.
 */
class MaterialityService
{
    public function __construct(
        private LeadScheduleService $schedules,
        private ApprovalService $approvals,
        private PlanningPapers $papers,
        private ActivityLogger $logger,
    ) {}

    /**
     * Candidate benchmark amounts (sen, absolute) from the adjusted TB, current and prior year.
     *
     * @return array{current: array<string, int>, prior: array<string, int>, loss: bool, ready: bool, issues: list<string>}
     */
    public function benchmarks(Engagement $engagement): array
    {
        $b = $this->schedules->build($engagement);
        $pick = fn (array $s) => [
            MaterialityBenchmark::ProfitBeforeTax->value => (int) $s['pbt'],
            MaterialityBenchmark::Revenue->value => (int) $s['revenue'],
            MaterialityBenchmark::TotalAssets->value => (int) $s['assets'],
            MaterialityBenchmark::TotalExpenses->value => (int) $s['expenses'],
            MaterialityBenchmark::Equity->value => (int) ($s['equity'] + $s['profit']),
            MaterialityBenchmark::GrossProfit->value => (int) $s['gross_profit'],
        ];
        $issues = [];
        if (! $b['has_current']) {
            $issues[] = 'No current-year trial balance has been imported.';
        }
        if ($b['checks']['unmapped_count'] > 0) {
            $issues[] = "{$b['checks']['unmapped_count']} account(s) are unmapped, so benchmark figures are incomplete.";
        }
        if ($b['checks']['sofp_difference'] !== 0) {
            $issues[] = 'The draft statement of financial position does not balance.';
        }

        return [
            'current' => $pick($b['cy']),
            'prior' => $b['has_prior'] ? $pick($b['py']) : [],
            'loss' => $b['cy']['pbt'] < 0,
            'ready' => $b['has_current'],
            'issues' => $issues,
        ];
    }

    /** The draft / pending / rejected version being worked on, if any. */
    public function openVersion(Engagement $engagement): ?MaterialityAssessment
    {
        return $engagement->materialityAssessments()
            ->whereIn('status', [MaterialityStatus::Draft->value, MaterialityStatus::PendingApproval->value, MaterialityStatus::Rejected->value])
            ->first();
    }

    /**
     * Create or update the open version.
     *
     * @param  array{stage?: string, benchmark: string, benchmark_basis: string, benchmark_amount?: ?string, benchmark_reason?: ?string,
     *     om_percentage: float|string, pm_percentage: float|string, ct_percentage: float|string, rationale: string, pm_rationale?: ?string,
     *     ct_rationale?: ?string, revision_reason?: ?string, specifics?: list<array{area: string, engagement_fsli_id?: ?int, amount: string, rationale: string}>}  $data
     */
    public function saveDraft(Engagement $engagement, array $data, User $actor, ?AiRun $aiRun = null): MaterialityAssessment
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement);

        return DB::transaction(function () use ($engagement, $data, $actor, $aiRun) {
            Engagement::whereKey($engagement->id)->lockForUpdate()->first();
            $open = $this->openVersion($engagement);
            if ($open?->status === MaterialityStatus::PendingApproval) {
                throw ValidationException::withMessages(['materiality' => 'This version is awaiting approval. Withdraw the request before editing.']);
            }
            $approved = $engagement->currentMateriality();
            $version = $open?->version ?? ((int) $engagement->materialityAssessments()->max('version') + 1);
            if ($version > 1 && blank($data['revision_reason'] ?? null)) {
                throw ValidationException::withMessages(['revision_reason' => 'Explain why materiality is being revised.']);
            }

            $values = $this->compute($engagement, $data);
            $stage = $version === 1 ? MaterialityStage::Planning : MaterialityStage::from($data['stage'] ?? MaterialityStage::Revised->value);

            $m = $open ?? new MaterialityAssessment(['engagement_id' => $engagement->id, 'version' => $version, 'created_by' => $actor->id]);
            $m->fill($values + [
                'stage' => $stage,
                'rationale' => $data['rationale'],
                'pm_rationale' => $data['pm_rationale'] ?? null,
                'ct_rationale' => $data['ct_rationale'] ?? null,
                'revision_reason' => $version > 1 ? $data['revision_reason'] : null,
                'updated_by' => $actor->id,
            ]);
            if ($aiRun) {
                $m->source = 'ai';
                $m->ai_run_id = $aiRun->id;
            }
            $m->status = MaterialityStatus::Draft;
            $m->save();

            $m->specifics()->delete();
            $fslis = $engagement->fslis()->pluck('id')->all();
            foreach ($data['specifics'] ?? [] as $i => $row) {
                $amount = $this->toSen($row['amount'] ?? '');
                if ($amount <= 0 || $amount >= $m->om_sen) {
                    throw ValidationException::withMessages(["specifics.{$i}.amount" => 'Specific materiality must be above zero and below overall materiality.']);
                }
                $fsliId = $row['engagement_fsli_id'] ?? null;
                if ($fsliId !== null && ! in_array((int) $fsliId, $fslis, true)) {
                    throw ValidationException::withMessages(["specifics.{$i}.engagement_fsli_id" => 'Unknown FSLI.']);
                }
                $m->specifics()->create(['area' => $row['area'], 'engagement_fsli_id' => $fsliId, 'amount_sen' => $amount, 'rationale' => $row['rationale']]);
            }

            $this->logger->log('materiality.saved', "Materiality v{$m->version} draft saved".($approved ? " (in force: v{$approved->version})" : ''), $m, [
                'om_sen' => $m->om_sen, 'pm_sen' => $m->pm_sen, 'ct_sen' => $m->ct_sen, 'source' => $m->source,
            ], $actor);

            return $m->fresh('specifics');
        });
    }

    public function submit(MaterialityAssessment $m, User $actor, ?string $comment): Approval
    {
        EngagementGuard::assertEditable($m->engagement);
        $this->authorise($actor, $m->engagement);
        if ($m->status !== MaterialityStatus::Draft) {
            throw ValidationException::withMessages(['materiality' => 'Only a draft version can be submitted.']);
        }

        return DB::transaction(function () use ($m, $actor, $comment) {
            $approval = $this->approvals->request($m, ApprovalType::Materiality, $actor, $comment, [
                'version' => $m->version, 'benchmark' => $m->benchmark->value, 'benchmark_amount_sen' => $m->benchmark_amount_sen,
                'om_sen' => $m->om_sen, 'pm_sen' => $m->pm_sen, 'ct_sen' => $m->ct_sen,
            ]);
            $m->forceFill(['status' => MaterialityStatus::PendingApproval])->save();

            return $approval;
        });
    }

    /** Called by the approvals engine inside the decision transaction. */
    public function applyDecision(MaterialityAssessment $m, Approval $approval): void
    {
        $actor = $approval->decided_by ? User::find($approval->decided_by) : User::find($approval->requested_by);

        match ($approval->status) {
            ApprovalStatus::Approved => (function () use ($m) {
                // Supersede the version in force first (only one approved version may exist).
                $m->engagement->materialityAssessments()->where('status', MaterialityStatus::Approved->value)->get()
                    ->each(fn (MaterialityAssessment $old) => $old->forceFill(['status' => MaterialityStatus::Superseded, 'superseded_at' => now()])->save());
                $m->forceFill(['status' => MaterialityStatus::Approved, 'approved_at' => now()])->save();
            })(),
            ApprovalStatus::Rejected => $m->forceFill(['status' => MaterialityStatus::Rejected])->save(),
            default => $m->forceFill(['status' => MaterialityStatus::Draft])->save(),
        };

        if ($approval->status === ApprovalStatus::Approved && $actor) {
            $this->papers->refresh($m->engagement, $actor, "Materiality v{$m->version} approved", [WorkingPaper::MATERIALITY, WorkingPaper::AUDIT_STRATEGY]);
        }
    }

    /** Start a new version from the one in force (planning → revised / final). */
    public function startRevision(Engagement $engagement, User $actor, string $stage, string $reason): MaterialityAssessment
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement);
        $current = $engagement->currentMateriality() ?? throw ValidationException::withMessages(['materiality' => 'There is no approved materiality to revise.']);
        if ($this->openVersion($engagement)) {
            throw ValidationException::withMessages(['materiality' => 'A version is already being prepared.']);
        }

        return $this->saveDraft($engagement, [
            'stage' => $stage,
            'benchmark' => $current->benchmark->value,
            'benchmark_basis' => $current->benchmark_basis->value,
            'benchmark_amount' => (string) ($current->benchmark_amount_sen / 100),
            'benchmark_reason' => $current->benchmark_reason,
            'om_percentage' => $current->om_percentage, 'pm_percentage' => $current->pm_percentage, 'ct_percentage' => $current->ct_percentage,
            'rationale' => $current->rationale, 'pm_rationale' => $current->pm_rationale, 'ct_rationale' => $current->ct_rationale,
            'revision_reason' => $reason,
            'specifics' => $current->specifics->map(fn ($s) => [
                'area' => $s->area, 'engagement_fsli_id' => $s->engagement_fsli_id, 'amount' => (string) ($s->amount_sen / 100), 'rationale' => $s->rationale,
            ])->all(),
        ], $actor);
    }

    public function discard(MaterialityAssessment $m, User $actor): void
    {
        EngagementGuard::assertEditable($m->engagement);
        $this->authorise($actor, $m->engagement);
        if (! $m->isEditable()) {
            throw ValidationException::withMessages(['materiality' => 'Only a draft or returned version can be discarded.']);
        }
        DB::transaction(function () use ($m, $actor) {
            if ($m->status === MaterialityStatus::Rejected) {
                $m->forceFill(['status' => MaterialityStatus::Draft])->save(); // drafts may be deleted; history stays in approvals
            }
            $this->logger->log('materiality.discarded', "Materiality v{$m->version} draft discarded", $m->engagement, ['version' => $m->version], $actor);
            $m->delete();
        });
    }

    /** Amounts are always derived on the server from the benchmark and the percentages. */
    private function compute(Engagement $engagement, array $data): array
    {
        $benchmark = MaterialityBenchmark::from($data['benchmark']);
        $basis = BenchmarkBasis::from($data['benchmark_basis']);
        $candidates = $this->benchmarks($engagement);
        $reason = trim((string) ($data['benchmark_reason'] ?? ''));

        $fromTb = match ($basis) {
            BenchmarkBasis::CurrentYear => $candidates['current'][$benchmark->value] ?? null,
            BenchmarkBasis::PriorYear => $candidates['prior'][$benchmark->value] ?? null,
            default => null,
        };
        if ($benchmark === MaterialityBenchmark::Other && in_array($basis, [BenchmarkBasis::CurrentYear, BenchmarkBasis::PriorYear], true)) {
            $fromTb = null;
        }

        if ($fromTb !== null) {
            $amount = abs($fromTb);
            if ($fromTb < 0 && $reason === '') {
                throw ValidationException::withMessages(['benchmark_reason' => 'The benchmark is negative (for example a loss). Explain why it is still appropriate, or choose another benchmark.']);
            }
        } else {
            if (in_array($basis, [BenchmarkBasis::CurrentYear, BenchmarkBasis::PriorYear], true) && $benchmark !== MaterialityBenchmark::Other) {
                throw ValidationException::withMessages(['benchmark_basis' => 'AUDIRA has no figure for this benchmark and year. Import and map the trial balance, or choose another basis.']);
            }
            $amount = $this->toSen($data['benchmark_amount'] ?? '');
            if ($reason === '') {
                throw ValidationException::withMessages(['benchmark_reason' => 'Explain the source of a benchmark amount that is not taken from the trial balance.']);
            }
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['benchmark_amount' => 'The benchmark amount must be above zero.']);
        }

        $om = (float) $data['om_percentage'];
        $pm = (float) $data['pm_percentage'];
        $ct = (float) $data['ct_percentage'];
        foreach (['om_percentage' => $om, 'pm_percentage' => $pm] as $k => $v) {
            if ($v <= 0 || $v > 100) {
                throw ValidationException::withMessages([$k => 'Enter a percentage above 0 and at most 100.']);
            }
        }
        if ($ct <= 0 || $ct >= $pm) {
            throw ValidationException::withMessages(['ct_percentage' => 'The clearly trivial threshold must be above zero and below performance materiality.']);
        }

        $omSen = (int) round($amount * $om / 100);
        $pmSen = (int) round($omSen * $pm / 100);
        $ctSen = (int) round($omSen * $ct / 100);
        if ($omSen <= 0 || $ctSen <= 0 || $ctSen >= $pmSen) {
            throw ValidationException::withMessages(['om_percentage' => 'These percentages produce amounts that are too small to use.']);
        }

        return [
            'benchmark' => $benchmark, 'benchmark_basis' => $basis, 'benchmark_amount_sen' => $amount,
            'benchmark_reason' => $reason !== '' ? $reason : null,
            'om_percentage' => $om, 'om_sen' => $omSen, 'pm_percentage' => $pm, 'pm_sen' => $pmSen, 'ct_percentage' => $ct, 'ct_sen' => $ctSen,
            'fs_snapshot' => $candidates,
        ];
    }

    public function toSen(string|int|float|null $value): int
    {
        $clean = str_replace([',', ' ', 'RM'], '', (string) $value);
        if ($clean === '' || ! is_numeric($clean)) {
            return 0;
        }

        return (int) round(((float) $clean) * 100);
    }

    private function authorise(User $actor, Engagement $engagement): void
    {
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.prepare_materiality')) {
            throw new AuthorizationException('Your role on this engagement does not allow preparing materiality.');
        }
    }
}
