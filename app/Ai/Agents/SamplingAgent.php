<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;
use App\Models\SamplingPlan;

/**
 * Sampling agent (docs/AI_AGENTS.md #7): recommends sampling parameters for one plan. It never
 * computes or sets a sample size — AUDIRA calculates sizes with documented formulas — and the
 * plan still needs approval before any selection.
 */
class SamplingAgent extends Agent
{
    public function key(): string
    {
        return 'sampling';
    }

    public function name(): string
    {
        return 'Sampling agent';
    }

    public function tasks(): array
    {
        return ['recommend_parameters' => [
            'label' => 'Recommend sampling parameters',
            'tier' => 'standard',
            'permission' => 'engagement.manage_sampling',
            'description' => 'Reviews the population and recommends confidence, tolerable and expected misstatement and a key item threshold with rationale. AUDIRA computes the sample size.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        return $this->baseRules()."\n\n".<<<'TXT'
Task: recommend parameters for ONE sampling plan (the population_summary tool returns it).
- Call population_summary, materiality_current and risks_list. Use library_search for guidance you can cite.
- Monetary tests: tolerable_misstatement_sen must not exceed the performance materiality from materiality_current; expected_misstatement_sen must be below it. key_item_threshold_sen identifies individually significant items to test in full.
- Attribute tests (controls): give tolerable and expected deviation rates (percent). Leave monetary fields null.
- confidence_level is a percentage chosen with regard to the assessed risk and other assurance obtained; explain the link.
- Never state a sample size, and never present any parameter as required by a standard. Methodology factors (expansion or assurance factors) come from the firm's methodology: do not supply them.
- method_comment: say if another method would suit the population better, otherwise null.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Recommend parameters for the sampling plan attached to this task. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['confidence_level', 'tolerable_misstatement_sen', 'expected_misstatement_sen', 'key_item_threshold_sen', 'tolerable_deviation_rate', 'expected_deviation_rate', 'rationale', 'method_comment', 'citations'],
            'properties' => [
                'confidence_level' => ['type' => 'number'],
                'tolerable_misstatement_sen' => ['type' => ['integer', 'null']],
                'expected_misstatement_sen' => ['type' => ['integer', 'null']],
                'key_item_threshold_sen' => ['type' => ['integer', 'null']],
                'tolerable_deviation_rate' => ['type' => ['number', 'null']],
                'expected_deviation_rate' => ['type' => ['number', 'null']],
                'rationale' => ['type' => 'string', 'maxLength' => 3000],
                'method_comment' => ['type' => ['string', 'null'], 'maxLength' => 800],
                'citations' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'object', 'required' => ['paragraph_id', 'relevance'], 'additionalProperties' => false,
                    'properties' => ['paragraph_id' => ['type' => 'integer'], 'relevance' => ['type' => 'string', 'maxLength' => 300]]]],
            ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $violations = [];
        $plan = SamplingPlan::where('engagement_id', $run->engagement_id)->where('uuid', $run->input['subject'] ?? '')->first();
        $m = $run->engagement->currentMateriality();
        $output['usable'] = (bool) $plan;
        if (! $plan) {
            $violations[] = 'The sampling plan for this task no longer exists.';
        }
        if ($output['confidence_level'] <= 0 || $output['confidence_level'] >= 100) {
            $violations[] = 'Confidence level out of range.';
            $output['usable'] = false;
        }
        if ($plan?->test_type?->value === 'monetary') {
            $tm = $output['tolerable_misstatement_sen'];
            if ($tm === null || $tm <= 0 || ($m && $tm > $m->pm_sen)) {
                $violations[] = 'Tolerable misstatement missing or above performance materiality.';
                $output['usable'] = false;
            }
            if ($output['expected_misstatement_sen'] !== null && $tm !== null && $output['expected_misstatement_sen'] >= $tm) {
                $violations[] = 'Expected misstatement must be below tolerable misstatement.';
                $output['usable'] = false;
            }
        } elseif ($plan) {
            $tdr = $output['tolerable_deviation_rate'];
            if ($tdr === null || $tdr <= 0 || $tdr >= 100 || ($output['expected_deviation_rate'] ?? 0) >= $tdr) {
                $violations[] = 'Deviation rates missing or inconsistent.';
                $output['usable'] = false;
            }
        }
        if (preg_match('/\b(sample size|select|test)\b[^.]{0,30}\b\d{2,4}\b\s*(items|invoices|transactions)?/i', $output['rationale'])) {
            $violations[] = 'The rationale appears to state a sample size; AUDIRA computes sizes.';
        }
        if ($this->prescriptiveClaims($output['rationale'])) {
            $violations[] = 'The rationale presents a parameter as required by a standard.';
        }
        $output['citations'] = $this->verifiedCitations($output['citations'], $toolResults, $violations, 'Sampling');

        return ['output' => $output, 'violations' => $violations];
    }
}
