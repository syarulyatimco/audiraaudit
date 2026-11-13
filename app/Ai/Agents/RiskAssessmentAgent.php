<?php

namespace App\Ai\Agents;

use App\Enums\Assertion;
use App\Enums\InherentRiskFactor;
use App\Models\AiRun;
use App\Models\Engagement;

/**
 * Risk Assessment agent (docs/AI_AGENTS.md #5). Proposes risks of material misstatement from the
 * analytics and the documented understanding of the entity. Accepted risks enter the register
 * as drafts; significance is the team's decision and needs partner approval.
 */
class RiskAssessmentAgent extends Agent
{
    public function key(): string
    {
        return 'risk_assessment';
    }

    public function name(): string
    {
        return 'Risk Assessment agent';
    }

    public function tasks(): array
    {
        return [
            'suggest_risks' => [
                'label' => 'Identify risks of material misstatement',
                'tier' => 'complex',
                'permission' => 'engagement.manage_risks',
                'description' => 'Proposes financial-statement and assertion-level risks with inherent risk factors, an initial assessment and cited library paragraphs. You choose which to add to the register.',
            ],
        ];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        $assertions = implode(', ', Assertion::values());
        $factors = implode(', ', InherentRiskFactor::values());

        return $this->baseRules()."\n\n".sprintf('Engagement %s: %s, %s client, reporting framework %s, period %s to %s.',
            $engagement->code, $engagement->engagement_type->label(), $engagement->client->entity_type->label(),
            $engagement->reporting_framework->label(), $engagement->period_start->toDateString(), $engagement->period_end->toDateString())
            ."\n\n".<<<TXT
Task: identify risks of material misstatement for the engagement team to consider.
- Call tb_analytics, fsli_list, entity_profile, materiality_current and risks_list. Use library_search to find guidance you can cite.
- Ground every risk in something specific from the tools: a fluctuation, an unusual balance, an opposite balance, a matter in the entity understanding, or the entity's industry. Say what the evidence is in "basis".
- Do not repeat risks already in risks_list. The presumed fraud risks (revenue recognition, management override) are handled separately by the team; do not propose them.
- level "assertion" needs fsli_codes from fsli_list and assertions from: {$assertions}. level "financial_statement" has neither.
- inherent_factors from: {$factors}.
- likelihood and magnitude: low, medium or high. suggest_significant is only a suggestion for risks at the upper end of the spectrum; the team decides and the engagement partner approves.
- planned_response: an overall response, not a detailed programme.
- Quote amounts only as returned by the tools. Return at most 12 risks, most important first.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Identify risks of material misstatement for this engagement. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return [
            'type' => 'object', 'required' => ['risks', 'summary'], 'additionalProperties' => false,
            'properties' => [
                'risks' => ['type' => 'array', 'maxItems' => 12, 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['title', 'description', 'basis', 'level', 'fsli_codes', 'assertions', 'inherent_factors', 'likelihood', 'magnitude', 'suggest_significant', 'fraud', 'planned_response', 'citations'],
                    'properties' => [
                        'title' => ['type' => 'string', 'maxLength' => 200],
                        'description' => ['type' => 'string', 'maxLength' => 1500],
                        'basis' => ['type' => 'string', 'maxLength' => 800],
                        'level' => ['type' => 'string', 'enum' => ['financial_statement', 'assertion']],
                        'fsli_codes' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 20]],
                        'assertions' => ['type' => 'array', 'maxItems' => 9, 'items' => ['type' => 'string', 'enum' => Assertion::values()]],
                        'inherent_factors' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string', 'enum' => InherentRiskFactor::values()]],
                        'likelihood' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        'magnitude' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        'suggest_significant' => ['type' => 'boolean'],
                        'fraud' => ['type' => 'boolean'],
                        'planned_response' => ['type' => 'string', 'maxLength' => 1500],
                        'citations' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'object', 'required' => ['paragraph_id', 'relevance'], 'additionalProperties' => false, 'properties' => [
                            'paragraph_id' => ['type' => 'integer'], 'relevance' => ['type' => 'string', 'maxLength' => 300],
                        ]]],
                    ],
                ]],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
            ],
        ];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $engagement = $run->engagement;
        $fslis = $engagement->fslis()->pluck('code')->all();
        $existing = $engagement->risks()->active()->pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->all();
        $known = $this->knownAmounts($toolResults);
        $violations = [];
        $kept = [];

        foreach ($output['risks'] as $i => $r) {
            $label = 'Risk '.($i + 1).' ('.$r['title'].')';
            $unknown = array_values(array_diff($r['fsli_codes'], $fslis));
            if ($unknown) {
                $violations[] = "{$label}: unknown FSLI code(s) ".implode(', ', $unknown).' removed.';
                $r['fsli_codes'] = array_values(array_intersect($r['fsli_codes'], $fslis));
            }
            if ($r['level'] === 'financial_statement') {
                $r['fsli_codes'] = [];
                $r['assertions'] = [];
            } elseif (! $r['fsli_codes'] || ! $r['assertions']) {
                $violations[] = "{$label}: an assertion-level risk needs at least one valid FSLI and assertion — removed.";

                continue;
            }
            if (in_array(mb_strtolower(trim($r['title'])), $existing, true)) {
                $violations[] = "{$label}: already in the risk register — removed.";

                continue;
            }
            $r['citations'] = $this->verifiedCitations($r['citations'], $toolResults, $violations, $label);
            $r['unverified_figures'] = array_values(array_diff($this->knownAmounts([$r['description'], $r['basis']]), $known));
            if ($r['unverified_figures']) {
                $violations[] = "{$label} quotes figures not returned by AUDIRA: ".implode(', ', $r['unverified_figures']).'.';
            }
            $kept[] = $r;
        }
        $output['risks'] = $kept;

        return ['output' => $output, 'violations' => $violations];
    }
}
