<?php

namespace App\Services\Programme;

use App\Enums\Assertion;
use App\Enums\ProcedureNature;
use App\Enums\ProcedureStatus;
use App\Enums\ProcedureTiming;
use App\Models\AiRun;
use App\Models\Engagement;
use App\Models\Procedure;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Services\Planning\PlanningPapers;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Audit programme (ISA 330): procedures that respond to assessed risks. Every procedure traces to
 * the risks it addresses; working paper A2 cannot be signed while an active risk has no response.
 */
class ProgrammeService
{
    public function __construct(private PlanningPapers $papers, private ActivityLogger $logger, private \App\Services\WorkingPapers\WorkingPaperService $workingPapers) {}

    /**
     * Open the working paper in which the procedure's work and evidence are documented (fieldwork
     * section). Idempotent: returns the existing paper if one is already linked.
     */
    public function workingPaper(Procedure $p, User $actor): WorkingPaper
    {
        EngagementGuard::assertEditable($p->engagement);
        $this->authorise($actor, $p->engagement, 'engagement.execute_procedures');
        $this->assertActive($p);
        if ($p->working_paper_id && $p->workingPaper) {
            return $p->workingPaper;
        }

        return DB::transaction(function () use ($p, $actor) {
            $section = \App\Enums\WorkingPaperSection::Fieldwork;
            $wp = $this->workingPapers->create($p->engagement, [
                'reference' => $this->workingPapers->suggestReference($p->engagement, $section),
                'title' => mb_substr("{$p->reference} {$p->title}", 0, 200), 'section' => $section->value,
                'objective' => "Perform procedure {$p->reference}".($p->assertions ? ' addressing '.implode(', ', $p->assertions) : '').":\n{$p->steps}",
            ], $actor);
            $p->forceFill(['working_paper_id' => $wp->id])->save();
            $this->logger->log('procedure.paper', "Working paper {$wp->reference} opened for procedure {$p->reference}", $p, ['working_paper' => $wp->reference], $actor);

            return $wp;
        });
    }

    public function create(Engagement $engagement, array $data, User $actor, string $source = 'manual', ?AiRun $aiRun = null): Procedure
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement, 'engagement.manage_programme');
        [$attrs, $riskIds] = $this->validate($engagement, $data);

        return DB::transaction(function () use ($engagement, $attrs, $riskIds, $actor, $source, $aiRun) {
            Engagement::whereKey($engagement->id)->lockForUpdate()->first();
            $p = $engagement->procedures()->create($attrs + [
                'reference' => $this->nextReference($engagement), 'source' => $source, 'ai_run_id' => $aiRun?->id,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $p->risks()->sync(collect($riskIds)->mapWithKeys(fn ($id) => [$id => ['firm_id' => $p->firm_id]])->all());
            $this->logger->log('procedure.created', "Procedure {$p->reference} {$p->title} added to the programme", $p, ['risks' => $riskIds, 'source' => $source], $actor);
            $this->papers->refresh($engagement, $actor, "Procedure {$p->reference} added", [WorkingPaper::AUDIT_PROGRAMME]);

            return $p;
        });
    }

    public function update(Procedure $p, array $data, User $actor): Procedure
    {
        EngagementGuard::assertEditable($p->engagement);
        $this->authorise($actor, $p->engagement, 'engagement.manage_programme');
        $this->assertActive($p);
        [$attrs, $riskIds] = $this->validate($p->engagement, $data);

        return DB::transaction(function () use ($p, $attrs, $riskIds, $actor) {
            $p->fill($attrs + ['updated_by' => $actor->id])->save();
            $p->risks()->sync(collect($riskIds)->mapWithKeys(fn ($id) => [$id => ['firm_id' => $p->firm_id]])->all());
            $this->logger->log('procedure.updated', "Procedure {$p->reference} updated", $p, ['fields' => array_keys($p->getChanges())], $actor);
            $this->papers->refresh($p->engagement, $actor, "Procedure {$p->reference} updated", [WorkingPaper::AUDIT_PROGRAMME]);

            return $p->fresh(['risks']);
        });
    }

    /** Execution status and conclusion, by any team member who executes procedures. */
    public function progress(Procedure $p, string $status, ?string $conclusion, User $actor): void
    {
        EngagementGuard::assertEditable($p->engagement);
        $this->authorise($actor, $p->engagement, 'engagement.execute_procedures');
        $this->assertActive($p);
        $st = ProcedureStatus::from($status);
        if ($st === ProcedureStatus::Completed && blank($conclusion)) {
            throw ValidationException::withMessages(['conclusion' => 'Record the conclusion before marking the procedure completed.']);
        }
        if ($st === ProcedureStatus::Completed && $p->samplingPlans()->whereNotIn('status', ['evaluated'])->exists()) {
            throw ValidationException::withMessages(['status' => 'Evaluate the sampling plans for this procedure first.']);
        }
        $p->forceFill(['status' => $st, 'conclusion' => filled($conclusion) ? trim($conclusion) : null, 'updated_by' => $actor->id])->save();
        $this->logger->log('procedure.progress', "Procedure {$p->reference} marked {$st->label()}", $p, [], $actor);
    }

    public function withdraw(Procedure $p, string $reason, User $actor): void
    {
        EngagementGuard::assertEditable($p->engagement);
        $this->authorise($actor, $p->engagement, 'engagement.manage_programme');
        $this->assertActive($p);
        if ($p->samplingPlans()->exists()) {
            throw ValidationException::withMessages(['procedure' => 'This procedure has sampling plans and cannot be withdrawn.']);
        }
        $p->forceFill(['withdrawn_at' => now(), 'withdrawn_reason' => $reason, 'updated_by' => $actor->id])->save();
        $this->logger->log('procedure.withdrawn', "Procedure {$p->reference} withdrawn", $p, ['reason' => $reason], $actor);
        $this->papers->refresh($p->engagement, $actor, "Procedure {$p->reference} withdrawn", [WorkingPaper::AUDIT_PROGRAMME]);
    }

    /** Active risks with no active responding procedure. */
    public function uncoveredRisks(Engagement $engagement)
    {
        return $engagement->risks()->active()->whereDoesntHave('procedures', fn ($q) => $q->whereNull('procedures.withdrawn_at'))->get();
    }

    /** @return array{0: array<string, mixed>, 1: list<int>} */
    private function validate(Engagement $engagement, array $data): array
    {
        $nature = ProcedureNature::tryFrom((string) ($data['nature'] ?? '')) ?? throw ValidationException::withMessages(['nature' => 'Choose the nature of the procedure.']);
        $timing = ProcedureTiming::tryFrom((string) ($data['timing'] ?? 'final')) ?? ProcedureTiming::Final;
        $assertions = array_values(array_unique(array_map('strval', (array) ($data['assertions'] ?? []))));
        if (array_diff($assertions, Assertion::values())) {
            throw ValidationException::withMessages(['assertions' => 'Unknown assertion.']);
        }
        $riskIds = array_values(array_unique(array_map('intval', (array) ($data['risk_ids'] ?? []))));
        $known = $engagement->risks()->active()->pluck('id')->all();
        if (array_diff($riskIds, $known)) {
            throw ValidationException::withMessages(['risk_ids' => 'Procedures can only respond to active risks of this engagement.']);
        }
        $fsli = $data['engagement_fsli_id'] ?? null;
        if ($fsli !== null && $fsli !== '' && ! $engagement->fslis()->whereKey($fsli)->exists()) {
            throw ValidationException::withMessages(['engagement_fsli_id' => 'Unknown FSLI.']);
        }
        $assignee = $data['assigned_to'] ?? null;
        if ($assignee && ! $engagement->members()->where('user_id', $assignee)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Assign the procedure to a member of the engagement team.']);
        }
        if (blank($data['title'] ?? null) || blank($data['steps'] ?? null)) {
            throw ValidationException::withMessages(['title' => 'A title and the procedure steps are required.']);
        }

        return [[
            'title' => trim($data['title']), 'steps' => trim($data['steps']), 'nature' => $nature, 'timing' => $timing,
            'assertions' => $assertions, 'engagement_fsli_id' => $fsli ?: null, 'assigned_to' => $assignee ?: null,
            'budget_hours' => filled($data['budget_hours'] ?? null) ? (float) $data['budget_hours'] : null,
        ], $riskIds];
    }

    private function assertActive(Procedure $p): void
    {
        if (! $p->isActive()) {
            throw ValidationException::withMessages(['procedure' => 'This procedure has been withdrawn.']);
        }
    }

    private function nextReference(Engagement $engagement): string
    {
        $n = $engagement->procedures()->count() + 1;
        do {
            $ref = sprintf('P%03d', $n++);
        } while ($engagement->procedures()->where('reference', $ref)->exists());

        return $ref;
    }

    private function authorise(User $actor, Engagement $engagement, string $perm): void
    {
        if (! Permissions::allowsOnEngagement($actor, $engagement, $perm)) {
            throw new AuthorizationException('Your role on this engagement does not allow this action.');
        }
    }
}
