<?php

namespace App\Services\Sampling;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\SampleTestType;
use App\Enums\SamplingApproach;
use App\Enums\SamplingMethod;
use App\Enums\SamplingStatus;
use App\Models\AiRun;
use App\Models\Approval;
use App\Models\Engagement;
use App\Models\PopulationItem;
use App\Models\SampleItem;
use App\Models\SamplingPlan;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\TrialBalance\AmountParser;
use App\Services\TrialBalance\LeadScheduleService;
use App\Services\TrialBalance\SpreadsheetReader;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Audit sampling lifecycle (CLAUDE.md §7):
 * draft (population + completeness reconciliation + parameters + documented size)
 *   → approval (engagement partner or manager, never the requester)
 *   → reproducible selection (seed + algorithm + population hash + per-item log)
 *   → results → evaluation (projection, upper limits, need for further procedures).
 */
class SamplingService
{
    public function __construct(
        private ApprovalService $approvals,
        private LeadScheduleService $schedules,
        private ActivityLogger $logger,
    ) {}

    public function create(Engagement $engagement, array $data, User $actor): SamplingPlan
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement, 'engagement.manage_sampling');
        $method = SamplingMethod::from($data['method']);
        $type = SampleTestType::from($data['test_type']);
        $approach = $method === SamplingMethod::Mus ? SamplingApproach::Statistical
            : ($method->alwaysNonStatistical() ? SamplingApproach::NonStatistical : SamplingApproach::from($data['approach'] ?? 'non_statistical'));
        if ($method === SamplingMethod::Mus && $type !== SampleTestType::Monetary) {
            throw ValidationException::withMessages(['method' => 'Monetary unit sampling is used for monetary (substantive) tests.']);
        }
        $procedure = filled($data['procedure_id'] ?? null) ? $engagement->procedures()->active()->whereKey($data['procedure_id'])->first() : null;
        if (filled($data['procedure_id'] ?? null) && ! $procedure) {
            throw ValidationException::withMessages(['procedure_id' => 'Unknown procedure.']);
        }
        $fsliId = $data['engagement_fsli_id'] ?? $procedure?->engagement_fsli_id;
        if ($fsliId && ! $engagement->fslis()->whereKey($fsliId)->exists()) {
            throw ValidationException::withMessages(['engagement_fsli_id' => 'Unknown FSLI.']);
        }

        return DB::transaction(function () use ($engagement, $data, $actor, $method, $type, $approach, $procedure, $fsliId) {
            Engagement::whereKey($engagement->id)->lockForUpdate()->first();
            $n = $engagement->samplingPlans()->count() + 1;
            $plan = $engagement->samplingPlans()->create([
                'reference' => sprintf('S%02d', $n), 'title' => trim($data['title']), 'objective' => trim($data['objective']),
                'test_type' => $type, 'approach' => $approach, 'method' => $method,
                'procedure_id' => $procedure?->id, 'engagement_fsli_id' => $fsliId ?: null,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->logger->log('sampling.created', "Sampling plan {$plan->reference} created ({$method->label()}, {$approach->label()})", $plan, [], $actor);

            return $plan;
        });
    }

    /** Replace the population from a CSV/XLSX file with reference and amount columns (description and date optional). */
    public function uploadPopulation(SamplingPlan $plan, UploadedFile $file, User $actor): void
    {
        $this->assertEditable($plan, $actor);
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'xlsx', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload a CSV or XLSX file.']);
        }
        $rows = app(SpreadsheetReader::class)->read($file->getRealPath(), $ext === 'txt' ? 'csv' : $ext, 100000);
        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $col = function (array $names) use ($header) {
            foreach ($header as $i => $h) {
                foreach ($names as $n) {
                    if ($h === $n || str_contains($h, $n)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $ref = $col(['reference', 'ref', 'invoice', 'document', 'doc no', 'item']);
        $amt = $col(['amount', 'value', 'balance', 'total']);
        $desc = $col(['description', 'customer', 'supplier', 'name', 'details']);
        $date = $col(['date']);
        if ($ref === null || $amt === null) {
            throw ValidationException::withMessages(['file' => 'The first row must name a reference column and an amount column.']);
        }

        $items = [];
        $errors = [];
        $seen = [];
        foreach (array_slice($rows, 1) as $i => $r) {
            $line = $i + 2;
            $reference = trim((string) ($r[$ref] ?? ''));
            if ($reference === '' && trim((string) ($r[$amt] ?? '')) === '') {
                continue;
            }
            try {
                $sen = AmountParser::toSen($r[$amt] ?? '');
            } catch (Throwable) {
                $errors[] = "Row {$line}: invalid amount.";

                continue;
            }
            if ($reference === '') {
                $errors[] = "Row {$line}: missing reference.";

                continue;
            }
            if (isset($seen[$reference])) {
                $errors[] = "Row {$line}: duplicate reference {$reference}.";

                continue;
            }
            $seen[$reference] = true;
            $d = $date !== null ? trim((string) ($r[$date] ?? '')) : '';
            $items[] = ['item_ref' => mb_substr($reference, 0, 100), 'description' => $desc !== null ? mb_substr(trim((string) ($r[$desc] ?? '')), 0, 300) : null,
                'amount_sen' => $sen, 'item_date' => $d !== '' && strtotime($d) ? date('Y-m-d', strtotime($d)) : null];
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 10)]);
        }
        if (! $items) {
            throw ValidationException::withMessages(['file' => 'No population items found.']);
        }

        DB::transaction(function () use ($plan, $items, $file, $actor) {
            $plan->populationItems()->delete();
            $hash = hash_init('sha256');
            foreach (array_chunk($items, 1000, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $k => $it) {
                    $rows[] = $it + ['seq' => $k + 1, 'sampling_plan_id' => $plan->id, 'firm_id' => $plan->firm_id];
                    hash_update($hash, ($k + 1).'|'.$it['item_ref'].'|'.$it['amount_sen']."\n");
                }
                PopulationItem::insert($rows);
            }
            $plan->forceFill([
                'population_source' => mb_substr($file->getClientOriginalName(), 0, 200),
                'population_count' => count($items),
                'population_value_sen' => array_sum(array_column($items, 'amount_sen')),
                'population_sha256' => hash_final($hash),
                'sample_size' => null, 'size_calculation' => null, 'updated_by' => $actor->id,
            ])->save();
            $this->logger->log('sampling.population', "Population for {$plan->reference} loaded: ".count($items).' items', $plan, ['sha256' => $plan->population_sha256], $actor);
        });
    }

    /**
     * Save parameters and compute the documented sample size.
     *
     * @param  array<string, mixed>  $data  amounts as RM strings; rates and confidence as percentages
     */
    public function saveParameters(SamplingPlan $plan, array $data, User $actor, ?AiRun $aiRun = null): SamplingPlan
    {
        try {
            return $this->doSaveParameters($plan, $data, $actor, $aiRun);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['parameters' => $e->getMessage()]);
        }
    }

    private function doSaveParameters(SamplingPlan $plan, array $data, User $actor, ?AiRun $aiRun): SamplingPlan
    {
        $this->assertEditable($plan, $actor);
        if ($plan->population_count === 0) {
            throw ValidationException::withMessages(['population' => 'Load the population first.']);
        }
        $sen = fn ($k) => filled($data[$k] ?? null) ? AmountParser::toSen($data[$k]) : null;
        $pct = fn ($k) => filled($data[$k] ?? null) ? (float) $data[$k] : null;

        // Completeness: the population must reconcile to a control total (default: the FSLI's adjusted balance).
        $control = $sen('control_total');
        $controlSource = $data['control_total_source'] ?? null;
        if ($control === null && $plan->engagement_fsli_id) {
            $s = $this->schedules->build($plan->engagement)['fslis'][$plan->engagement_fsli_id] ?? null;
            if ($s) {
                $control = abs((int) $s['cy_adj']);
                $controlSource = 'Adjusted balance of FSLI '.$s['fsli']->name;
            }
        }
        if ($control === null) {
            throw ValidationException::withMessages(['control_total' => 'Enter the control total the population must reconcile to (e.g. ledger or sub-ledger total).']);
        }
        $difference = $plan->population_value_sen - $control;
        if ($difference !== 0 && blank($data['reconciliation_note'] ?? null)) {
            throw ValidationException::withMessages(['reconciliation_note' => 'The population differs from the control total by RM '.number_format($difference / 100, 2).'. Explain the difference or load a complete population.']);
        }

        $attrs = [
            'control_total_sen' => $control, 'control_total_source' => $controlSource ? mb_substr($controlSource, 0, 200) : null,
            'reconciliation_note' => $difference !== 0 ? trim($data['reconciliation_note']) : null,
            'method_rationale' => filled($data['method_rationale'] ?? null) ? trim($data['method_rationale']) : null,
        ];

        $calc = null;
        try {
            if ($plan->test_type === SampleTestType::Attributes) {
                $tdr = $pct('tolerable_deviation_rate');
                $edr = $pct('expected_deviation_rate') ?? 0.0;
                $cl = $pct('confidence_level');
                if ($plan->approach === SamplingApproach::Statistical || ! $plan->method->isManual()) {
                    if ($tdr === null || $cl === null) {
                        throw ValidationException::withMessages(['tolerable_deviation_rate' => 'Enter the tolerable deviation rate and confidence level.']);
                    }
                    $calc = SamplingMath::attributesSize($tdr / 100, $edr / 100, $cl / 100);
                }
                $attrs += ['tolerable_deviation_rate' => $tdr, 'expected_deviation_rate' => $edr, 'confidence_level' => $cl];
            } else {
                $tm = $sen('tolerable_misstatement');
                $em = $sen('expected_misstatement') ?? 0;
                $key = $sen('key_item_threshold');
                $materiality = $plan->engagement->currentMateriality();
                if (! $materiality) {
                    throw ValidationException::withMessages(['tolerable_misstatement' => 'Monetary sampling needs approved materiality first.']);
                }
                $limit = $materiality->specifics()->where('engagement_fsli_id', $plan->engagement_fsli_id)->min('amount_sen') ?: $materiality->pm_sen;
                if ($tm === null || $tm <= 0 || $tm > $limit) {
                    throw ValidationException::withMessages(['tolerable_misstatement' => 'Tolerable misstatement must be above zero and not exceed RM '.number_format($limit / 100, 2).' (performance or specific materiality).']);
                }
                if ($em >= $tm) {
                    throw ValidationException::withMessages(['expected_misstatement' => 'Expected misstatement must be below tolerable misstatement.']);
                }
                if ($key !== null && $key <= 0) {
                    throw ValidationException::withMessages(['key_item_threshold' => 'The key item threshold must be above zero.']);
                }
                $nonKeyValue = $plan->populationItems()->when($key, fn ($q) => $q->whereRaw('abs(amount_sen) < ?', [$key]))->sum(DB::raw($plan->method === SamplingMethod::Mus ? 'GREATEST(amount_sen, 0)' : 'abs(amount_sen)'));
                $attrs += ['tolerable_misstatement_sen' => $tm, 'expected_misstatement_sen' => $em, 'key_item_threshold_sen' => $key,
                    'confidence_level' => $pct('confidence_level'), 'expansion_factor' => $pct('expansion_factor'), 'assurance_factor' => $pct('assurance_factor')];

                if ($plan->method === SamplingMethod::Mus) {
                    if ($attrs['confidence_level'] === null) {
                        throw ValidationException::withMessages(['confidence_level' => 'Enter the confidence level.']);
                    }
                    $calc = SamplingMath::musSize((int) $nonKeyValue, $tm, $em, $attrs['confidence_level'] / 100, $attrs['expansion_factor']);
                } elseif ($plan->approach === SamplingApproach::Statistical) {
                    // Statistical random / systematic / stratified monetary samples are sized on monetary units with Poisson factors.
                    if ($attrs['confidence_level'] === null) {
                        throw ValidationException::withMessages(['confidence_level' => 'Enter the confidence level.']);
                    }
                    $calc = SamplingMath::musSize((int) $nonKeyValue, $tm, $em, $attrs['confidence_level'] / 100, $attrs['expansion_factor']);
                    $calc['formula'] .= ' (applied to the non-key population)';
                } elseif (! $plan->method->isManual()) {
                    if ($attrs['assurance_factor'] === null) {
                        throw ValidationException::withMessages(['assurance_factor' => "Enter the assurance factor from your firm's sampling methodology and document it in the rationale."]);
                    }
                    $calc = SamplingMath::nonStatisticalSize((int) $nonKeyValue, $tm, $attrs['assurance_factor']);
                }
                if ($plan->method === SamplingMethod::Stratified) {
                    $bounds = array_values(array_filter(array_map(fn ($b) => AmountParser::toSen($b), array_filter((array) ($data['strata'] ?? []), 'filled')), fn ($b) => $b > 0));
                    if (! $bounds) {
                        throw ValidationException::withMessages(['strata' => 'Define at least one stratum boundary.']);
                    }
                    sort($bounds);
                    $attrs['strata'] = $bounds;
                }
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['parameters' => $e->getMessage()]);
        }

        if ($plan->method->isManual() && blank($attrs['method_rationale'])) {
            throw ValidationException::withMessages(['method_rationale' => 'Explain how items will be chosen and why this non-statistical selection is appropriate.']);
        }
        if (! $plan->method->isManual() && $plan->approach === SamplingApproach::NonStatistical && blank($attrs['method_rationale'])) {
            throw ValidationException::withMessages(['method_rationale' => 'Document the methodology behind the factors used.']);
        }

        $attrs['sample_size'] = $calc['size'] ?? null;
        $attrs['size_calculation'] = $calc;
        if ($calc && $calc['size'] > max(1, $plan->population_count)) {
            $attrs['sample_size'] = $plan->population_count;
            $attrs['size_calculation']['capped_to_population'] = true;
        }
        $attrs['updated_by'] = $actor->id;
        if ($aiRun) {
            $attrs['source'] = 'ai';
            $attrs['ai_run_id'] = $aiRun->id;
        }

        $plan->fill($attrs)->save();
        if ($plan->status === SamplingStatus::Rejected) {
            $plan->forceFill(['status' => SamplingStatus::Draft])->save();
        }
        $this->logger->log('sampling.parameters', "Sampling plan {$plan->reference} parameters saved".($calc ? " — sample size {$attrs['sample_size']}" : ''), $plan, ['calculation' => $calc], $actor);

        return $plan->fresh();
    }

    public function submit(SamplingPlan $plan, User $actor, ?string $comment): Approval
    {
        $this->assertEditable($plan, $actor);
        if ($plan->population_count === 0 || $plan->control_total_sen === null) {
            throw ValidationException::withMessages(['plan' => 'Load the population and save the parameters before submitting.']);
        }
        if (! $plan->method->isManual() && ! $plan->sample_size) {
            throw ValidationException::withMessages(['plan' => 'The sample size has not been calculated.']);
        }

        return DB::transaction(function () use ($plan, $actor, $comment) {
            $approval = $this->approvals->request($plan, ApprovalType::SamplingPlan, $actor, $comment, [
                'method' => $plan->method->value, 'approach' => $plan->approach->value, 'population_sha256' => $plan->population_sha256,
                'population_value_sen' => $plan->population_value_sen, 'sample_size' => $plan->sample_size, 'calculation' => $plan->size_calculation,
            ]);
            $plan->forceFill(['status' => SamplingStatus::PendingApproval])->save();

            return $approval;
        });
    }

    public function applyDecision(SamplingPlan $plan, Approval $approval): void
    {
        $plan->forceFill(['status' => match ($approval->status) {
            ApprovalStatus::Approved => SamplingStatus::Approved,
            ApprovalStatus::Rejected => SamplingStatus::Rejected,
            default => SamplingStatus::Draft,
        }])->save();
    }

    /**
     * Perform the selection on an approved plan. Manual methods (judgemental, haphazard) take the
     * auditor's chosen population item ids with a reason for each.
     *
     * @param  array<int, string>  $manual  population_item_id => reason
     */
    public function select(SamplingPlan $plan, User $actor, array $manual = []): int
    {
        EngagementGuard::assertEditable($plan->engagement);
        $this->authorise($actor, $plan->engagement, 'engagement.manage_sampling');
        if ($plan->status !== SamplingStatus::Approved) {
            throw ValidationException::withMessages(['plan' => 'Only an approved plan can be selected.']);
        }

        return DB::transaction(function () use ($plan, $actor, $manual) {
            $items = $plan->populationItems()->get(['id', 'amount_sen'])->map(fn ($i) => ['id' => $i->id, 'amount' => $i->amount_sen])->all();
            $rows = [];
            if ($plan->method->isManual()) {
                $valid = array_column($items, 'id');
                foreach ($manual as $id => $reason) {
                    if (! in_array((int) $id, $valid, true) || blank($reason)) {
                        throw ValidationException::withMessages(['items' => 'Choose items from the population and give a reason for each.']);
                    }
                }
                if (! $manual) {
                    throw ValidationException::withMessages(['items' => 'Choose at least one item.']);
                }
                $order = 1;
                foreach ($manual as $id => $reason) {
                    $rows[] = ['population_item_id' => (int) $id, 'selection_order' => $order++, 'selection_reason' => $plan->method->value, 'selection_note' => mb_substr(trim($reason), 0, 1000)];
                }
                $plan->forceFill(['selection_algorithm' => 'manual (auditor selection)'])->save();
            } else {
                $seed = random_int(1, PHP_INT_MAX);
                $picked = SampleSelector::select($plan->method, $items, (int) $plan->sample_size, $seed, $plan->key_item_threshold_sen, $plan->strata ?? []);
                foreach ($picked as $k => $p) {
                    $rows[] = ['population_item_id' => $p['id'], 'selection_order' => $k + 1, 'selection_reason' => $p['reason'], 'stratum' => $p['stratum'], 'selection_point_sen' => $p['point']];
                }
                $plan->forceFill(['selection_seed' => $seed, 'selection_algorithm' => SampleSelector::ALGORITHM])->save();
            }
            foreach ($rows as $r) {
                $plan->sampleItems()->create($r);
            }
            $plan->forceFill(['status' => SamplingStatus::Selected, 'selected_at' => now(), 'selected_by' => $actor->id])->save();
            $this->logger->log('sampling.selected', "Sample selected for {$plan->reference}: ".count($rows).' items', $plan, [
                'seed' => $plan->selection_seed, 'algorithm' => $plan->selection_algorithm, 'population_sha256' => $plan->population_sha256,
            ], $actor);

            return count($rows);
        });
    }

    /** Re-perform the selection from the stored seed and confirm it matches the recorded sample. */
    public function verifySelection(SamplingPlan $plan): bool
    {
        if ($plan->method->isManual() || $plan->selection_seed === null) {
            return false;
        }
        $items = $plan->populationItems()->get(['id', 'amount_sen'])->map(fn ($i) => ['id' => $i->id, 'amount' => $i->amount_sen])->all();
        $again = array_column(SampleSelector::select($plan->method, $items, (int) $plan->sample_size, $plan->selection_seed, $plan->key_item_threshold_sen, $plan->strata ?? []), 'id');

        return $again === $plan->sampleItems()->pluck('population_item_id')->all();
    }

    public function recordResult(SampleItem $item, array $data, User $actor): void
    {
        $plan = SamplingPlan::findOrFail($item->sampling_plan_id);
        EngagementGuard::assertEditable($plan->engagement);
        $this->authorise($actor, $plan->engagement, 'engagement.execute_procedures');
        if ($plan->status !== SamplingStatus::Selected) {
            throw ValidationException::withMessages(['result' => 'Results can only be recorded while the sample is being tested.']);
        }
        $result = $data['result'] ?? null;
        if (! in_array($result, ['no_exception', 'exception'], true)) {
            throw ValidationException::withMessages(['result' => 'Choose a result.']);
        }
        $audited = null;
        if ($plan->test_type === SampleTestType::Monetary) {
            $audited = $result === 'exception' ? (filled($data['audited_amount'] ?? null) ? AmountParser::toSen($data['audited_amount']) : null) : $item->populationItem->amount_sen;
            if ($result === 'exception' && $audited === null) {
                throw ValidationException::withMessages(['audited_amount' => 'Enter the audited (correct) amount for an exception.']);
            }
        }
        if ($result === 'exception' && blank($data['result_note'] ?? null)) {
            throw ValidationException::withMessages(['result_note' => 'Describe the exception.']);
        }
        $evidence = $item->evidence_document_id;
        if (array_key_exists('evidence_document', $data)) {
            $evidence = null;
            if (filled($data['evidence_document'])) {
                $evidence = \App\Models\Document::where('engagement_id', $plan->engagement_id)->where('uuid', $data['evidence_document'])->value('id')
                    ?? throw ValidationException::withMessages(['evidence_document' => 'Choose a document from this engagement.']);
            }
        }
        $item->forceFill(['result' => $result, 'audited_amount_sen' => $audited, 'result_note' => filled($data['result_note'] ?? null) ? trim($data['result_note']) : null,
            'evidence_document_id' => $evidence, 'tested_by' => $actor->id, 'tested_at' => now()])->save();
    }

    /** Evaluate a fully tested sample and record the auditor's conclusion. */
    public function evaluate(SamplingPlan $plan, string $conclusion, User $actor): array
    {
        EngagementGuard::assertEditable($plan->engagement);
        $this->authorise($actor, $plan->engagement, 'engagement.manage_sampling');
        if ($plan->status !== SamplingStatus::Selected) {
            throw ValidationException::withMessages(['plan' => 'Only a selected sample can be evaluated.']);
        }
        if ($plan->sampleItems()->whereNull('result')->exists()) {
            throw ValidationException::withMessages(['plan' => 'Record a result for every selected item first.']);
        }
        if (blank($conclusion)) {
            throw ValidationException::withMessages(['conclusion' => 'Record your conclusion on the sample.']);
        }
        $evaluation = $this->computeEvaluation($plan);
        $plan->forceFill(['evaluation' => $evaluation, 'conclusion' => trim($conclusion), 'status' => SamplingStatus::Evaluated])->save();
        $this->logger->log('sampling.evaluated', "Sample {$plan->reference} evaluated", $plan, ['evaluation' => $evaluation], $actor);

        return $evaluation;
    }

    public function computeEvaluation(SamplingPlan $plan): array
    {
        $items = $plan->sampleItems()->with('populationItem')->get();
        $cl = ($plan->confidence_level ?? 0) / 100;

        if ($plan->test_type === SampleTestType::Attributes) {
            $deviations = $items->where('result', 'exception')->count();
            $e = $plan->approach === SamplingApproach::Statistical && $cl > 0
                ? SamplingMath::attributesEvaluation($items->count(), $deviations, $cl, ($plan->tolerable_deviation_rate ?? 0) / 100)
                : ['method' => 'Non-statistical attributes evaluation', 'sample_size' => $items->count(), 'deviations' => $deviations,
                    'sample_rate' => $items->count() ? round($deviations / $items->count(), 4) : null, 'tolerable_rate' => ($plan->tolerable_deviation_rate ?? 0) / 100,
                    'exceeds_tolerable' => $items->count() && $deviations / $items->count() > ($plan->tolerable_deviation_rate ?? 0) / 100];
            $e['further_procedures_indicated'] = $e['exceeds_tolerable'] || $deviations > 0 && $plan->expected_deviation_rate !== null && $deviations / max(1, $items->count()) > $plan->expected_deviation_rate / 100;

            return $e;
        }

        $key = $items->where('selection_reason', 'key_item');
        $sampled = $items->where('selection_reason', '!=', 'key_item');
        $keyMis = (int) $key->sum(fn ($i) => $i->misstatementSen());
        $tm = (int) $plan->tolerable_misstatement_sen;

        if ($plan->method === SamplingMethod::Mus) {
            $interval = (int) ($plan->size_calculation['interval_sen'] ?? 0);
            $e = SamplingMath::musEvaluation($sampled->map(fn ($i) => [$i->populationItem->amount_sen, $i->audited_amount_sen ?? $i->populationItem->amount_sen])->values()->all(), $interval, $cl, $keyMis, $tm);
        } else {
            $nonKeyValue = (int) $plan->populationItems()->when($plan->key_item_threshold_sen, fn ($q) => $q->whereRaw('abs(amount_sen) < ?', [$plan->key_item_threshold_sen]))->sum(DB::raw('abs(amount_sen)'));
            if ($plan->method === SamplingMethod::Stratified) {
                $projected = 0;
                foreach ($sampled->groupBy('stratum') as $stratum => $group) {
                    $strataValue = $this->stratumValue($plan, (int) $stratum);
                    $projected += SamplingMath::ratioProjection((int) $group->sum(fn ($i) => abs($i->populationItem->amount_sen)), (int) $group->sum(fn ($i) => $i->misstatementSen()), $strataValue);
                }
            } else {
                $projected = SamplingMath::ratioProjection((int) $sampled->sum(fn ($i) => abs($i->populationItem->amount_sen)), (int) $sampled->sum(fn ($i) => $i->misstatementSen()), $nonKeyValue);
            }
            $e = [
                'method' => $plan->method->isManual() ? 'Judgemental/haphazard sample — misstatements are projected by ratio as an indication only' : 'Ratio projection'.($plan->method === SamplingMethod::Stratified ? ' by stratum' : ''),
                'sample_misstatement_sen' => (int) $sampled->sum(fn ($i) => $i->misstatementSen()),
                'projected_misstatement_sen' => $projected,
                'key_item_misstatement_sen' => $keyMis,
                'projected_total_sen' => $projected + $keyMis,
                'tolerable_sen' => $tm,
                'exceeds_tolerable' => $projected + $keyMis > $tm,
            ];
        }
        $expected = (int) ($plan->expected_misstatement_sen ?? 0);
        $e['expected_in_planning_sen'] = $expected;
        // Further procedures are indicated when the result breaches tolerable misstatement or exceeds what the sample size assumed.
        $e['further_procedures_indicated'] = $e['exceeds_tolerable'] || ($e['projected_total_sen'] ?? 0) > $expected;

        return $e;
    }

    private function stratumValue(SamplingPlan $plan, int $stratum): int
    {
        $bounds = $plan->strata ?? [];
        $lower = $stratum > 1 ? $bounds[$stratum - 2] : null;
        $upper = $bounds[$stratum - 1] ?? null;

        return (int) $plan->populationItems()
            ->when($plan->key_item_threshold_sen, fn ($q) => $q->whereRaw('abs(amount_sen) < ?', [$plan->key_item_threshold_sen]))
            ->when($lower !== null, fn ($q) => $q->whereRaw('abs(amount_sen) > ?', [$lower]))
            ->when($upper !== null, fn ($q) => $q->whereRaw('abs(amount_sen) <= ?', [$upper]))
            ->sum(DB::raw('abs(amount_sen)'));
    }

    private function assertEditable(SamplingPlan $plan, User $actor): void
    {
        EngagementGuard::assertEditable($plan->engagement);
        $this->authorise($actor, $plan->engagement, 'engagement.manage_sampling');
        if (! $plan->isEditable()) {
            throw ValidationException::withMessages(['plan' => 'This plan is '.strtolower($plan->status->label()).' and can no longer be changed.']);
        }
    }

    private function authorise(User $actor, Engagement $engagement, string $perm): void
    {
        if (! Permissions::allowsOnEngagement($actor, $engagement, $perm)) {
            throw new AuthorizationException('Your role on this engagement does not allow this action.');
        }
    }
}
