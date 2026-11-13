<?php

namespace App\Ai\Tools;

use Illuminate\Support\Facades\DB;

class PopulationSummaryTool implements Tool
{
    public function name(): string
    {
        return 'population_summary';
    }

    public function description(): string
    {
        return 'Summary statistics of the population of the sampling plan this task was started for: count, total, largest items, negative items and a value distribution (amounts in sen), plus the plan\'s test type, method and linked FSLI.';
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
        // The plan comes from the run's input (set by the user who started the task), never from the model.
        $plan = $ctx->engagement->samplingPlans()->where('uuid', $ctx->run->input['subject'] ?? '')->first();
        if (! $plan) {
            return ['error' => 'No sampling plan is attached to this task.'];
        }
        $q = fn () => DB::table('population_items')->where('sampling_plan_id', $plan->id);
        $bands = [];
        foreach ([[0, 100000], [100000, 1000000], [1000000, 10000000], [10000000, null]] as [$lo, $hi]) {
            $b = $q()->whereRaw('abs(amount_sen) >= ?', [$lo])->when($hi, fn ($w) => $w->whereRaw('abs(amount_sen) < ?', [$hi]));
            $bands[] = ['from_sen' => $lo, 'to_sen' => $hi, 'count' => (clone $b)->count(), 'value_sen' => (int) (clone $b)->sum(DB::raw('abs(amount_sen)'))];
        }

        return [
            'plan' => ['reference' => $plan->reference, 'title' => $plan->title, 'objective' => $plan->objective, 'test_type' => $plan->test_type->value,
                'method' => $plan->method->value, 'approach' => $plan->approach->value, 'fsli' => $plan->fsli?->name],
            'population' => [
                'count' => $plan->population_count, 'total_sen' => $plan->population_value_sen,
                'negative_items' => $q()->where('amount_sen', '<', 0)->count(),
                'largest' => $q()->orderByRaw('abs(amount_sen) desc')->limit(10)->get(['item_ref', 'amount_sen'])->map(fn ($r) => ['ref' => $r->item_ref, 'amount_sen' => (int) $r->amount_sen])->all(),
                'bands' => $bands,
            ],
        ];
    }
}
