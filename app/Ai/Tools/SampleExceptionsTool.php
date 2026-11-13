<?php

namespace App\Ai\Tools;

use App\Enums\SampleTestType;
use App\Models\Finding;

/** Samples with exceptions: the exceptions found, the stored evaluation and amounts already recorded as findings. */
class SampleExceptionsTool implements Tool
{
    public function name(): string
    {
        return 'sample_exceptions';
    }

    public function description(): string
    {
        return 'Every sample with exceptions in this engagement: plan reference, FSLI code, procedure, test type, each exception (item, recorded and audited amounts in sen, misstatement = recorded − audited, auditor note), the factual and projected misstatement totals in sen, deviations for control tests, and the findings already recorded from the sample.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function requiredPermission(): string
    {
        return 'engagement.view';
    }

    public function handle(ToolContext $ctx, array $input): array
    {
        $plans = $ctx->engagement->samplingPlans()->with(['fsli', 'procedure'])->whereIn('status', ['selected', 'evaluated'])->get();
        $out = [];
        foreach ($plans as $plan) {
            $ex = $plan->sampleItems()->with('populationItem')->where('result', 'exception')->get();
            if ($ex->isEmpty()) {
                continue;
            }
            $monetary = $plan->test_type === SampleTestType::Monetary;
            $factual = $monetary ? (int) $ex->sum(fn ($i) => $i->misstatementSen()) : null;
            $total = $monetary && $plan->evaluation ? (int) ($plan->evaluation['projected_total_sen'] ?? $factual) : null;
            $out[] = [
                'plan_reference' => $plan->reference, 'title' => $plan->title, 'status' => $plan->status->value, 'test_type' => $plan->test_type->value,
                'fsli_code' => $plan->fsli?->code, 'procedure_reference' => $plan->procedure?->reference,
                'exceptions' => $ex->map(fn ($i) => ['item_ref' => $i->populationItem->item_ref, 'recorded_sen' => (int) $i->populationItem->amount_sen,
                    'audited_sen' => $i->audited_amount_sen, 'misstatement_sen' => $monetary ? $i->misstatementSen() : null, 'note' => $i->result_note])->values()->all(),
                'factual_misstatement_sen' => $factual,
                'projected_remainder_sen' => $total !== null ? $total - $factual : null,
                'deviations' => $monetary ? null : $ex->count(), 'sample_size' => $plan->sampleItems()->count(),
                'evaluation_method' => $plan->evaluation['method'] ?? null,
                'conclusion' => $plan->conclusion,
                'findings_recorded' => Finding::where('sampling_plan_id', $plan->id)->active()->get(['reference', 'misstatement_class', 'amount_sen'])
                    ->map(fn ($f) => ['reference' => $f->reference, 'class' => $f->misstatement_class?->value, 'amount_sen' => $f->amount_sen])->all(),
            ];
        }

        return ['samples' => $out];
    }
}
