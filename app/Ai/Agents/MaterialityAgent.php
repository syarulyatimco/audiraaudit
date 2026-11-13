<?php

namespace App\Ai\Agents;

use App\Enums\MaterialityBenchmark;
use App\Models\AiRun;
use App\Models\Engagement;
use App\Services\Planning\MaterialityService;

/**
 * Materiality agent (docs/AI_AGENTS.md #4). Recommends a benchmark and percentages with
 * rationale and cited library paragraphs. It never approves: a team member may copy the
 * recommendation into a draft, which the engagement partner must approve (CLAUDE.md §6, §9).
 */
class MaterialityAgent extends Agent
{
    public function __construct(private MaterialityService $materiality) {}

    public function key(): string
    {
        return 'materiality';
    }

    public function name(): string
    {
        return 'Materiality agent';
    }

    public function tasks(): array
    {
        return [
            'recommend_materiality' => [
                'label' => 'Recommend materiality',
                'tier' => 'complex',
                'permission' => 'engagement.prepare_materiality',
                'description' => 'Recommends a benchmark, percentages for overall, performance and clearly trivial amounts, and any specific materiality, with rationale and cited library paragraphs. Only a draft is created, and only if you choose to use it.',
            ],
        ];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        $benchmarks = implode(', ', array_keys(MaterialityBenchmark::options()));

        return $this->baseRules()."\n\n".sprintf('Engagement %s: %s, %s client, reporting framework %s, period %s to %s.',
            $engagement->code, $engagement->engagement_type->label(), $engagement->client->entity_type->label(),
            $engagement->reporting_framework->label(), $engagement->period_start->toDateString(), $engagement->period_end->toDateString())
            ."\n\n".<<<TXT
Task: recommend materiality for the engagement team to consider.
- Call fs_benchmarks and entity_profile. Use library_search (for example "materiality benchmark", "performance materiality", "clearly trivial") to find guidance you can cite.
- Choose one benchmark from: {$benchmarks}. Prefer the measure that users of this entity's financial statements focus on; consider the entity type (for example a JMB/MC strata body, cooperative or not-for-profit may be better served by expenses or revenue than by profit). Explain alternatives you considered and why you rejected them.
- benchmark_amount_sen must be the absolute value of the fs_benchmarks figure for the chosen benchmark and year. Do not compute or adjust benchmark amounts yourself; if normalisation would be appropriate, say so in the rationale and leave the decision to the team.
- Percentages are professional judgement within the firm's methodology. Explain the factors behind each; never present a percentage as required by a standard.
- Suggest specific materiality only where the entity profile gives a reason, with an amount in sen below overall materiality.
- citations: paragraph_ids returned by library_search that support the rationale, each with a short note on relevance. If the library returns nothing relevant, return an empty list.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Recommend materiality for this engagement. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        $citation = ['type' => 'object', 'required' => ['paragraph_id', 'relevance'], 'additionalProperties' => false, 'properties' => [
            'paragraph_id' => ['type' => 'integer'], 'relevance' => ['type' => 'string', 'maxLength' => 300],
        ]];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['benchmark', 'benchmark_year', 'benchmark_amount_sen', 'om_percentage', 'pm_percentage', 'ct_percentage', 'rationale', 'pm_rationale', 'ct_rationale', 'alternatives', 'specifics', 'citations', 'summary'],
            'properties' => [
                'benchmark' => ['type' => 'string', 'enum' => array_values(array_diff(MaterialityBenchmark::values(), ['other']))],
                'benchmark_year' => ['type' => 'string', 'enum' => ['current_year', 'prior_year']],
                'benchmark_amount_sen' => ['type' => 'integer'],
                'om_percentage' => ['type' => 'number'],
                'pm_percentage' => ['type' => 'number'],
                'ct_percentage' => ['type' => 'number'],
                'rationale' => ['type' => 'string', 'maxLength' => 3000],
                'pm_rationale' => ['type' => 'string', 'maxLength' => 1500],
                'ct_rationale' => ['type' => 'string', 'maxLength' => 1500],
                'alternatives' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'object', 'required' => ['benchmark', 'reason_rejected'], 'additionalProperties' => false, 'properties' => [
                    'benchmark' => ['type' => 'string', 'maxLength' => 40], 'reason_rejected' => ['type' => 'string', 'maxLength' => 500],
                ]]],
                'specifics' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'object', 'required' => ['area', 'amount_sen', 'rationale'], 'additionalProperties' => false, 'properties' => [
                    'area' => ['type' => 'string', 'maxLength' => 200], 'amount_sen' => ['type' => 'integer'], 'rationale' => ['type' => 'string', 'maxLength' => 800],
                ]]],
                'citations' => ['type' => 'array', 'maxItems' => 10, 'items' => $citation],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
            ],
        ];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $violations = [];
        $b = $this->materiality->benchmarks($run->engagement);
        $source = $output['benchmark_year'] === 'prior_year' ? $b['prior'] : $b['current'];
        $actual = $source[$output['benchmark']] ?? null;

        if ($actual === null) {
            $violations[] = 'The recommended benchmark has no figure in the trial balance for that year.';
            $output['usable'] = false;
        } else {
            $output['usable'] = true;
            if ((int) $output['benchmark_amount_sen'] !== abs($actual)) {
                $violations[] = sprintf('Benchmark amount corrected from %d to the AUDIRA figure %d (sen).', $output['benchmark_amount_sen'], abs($actual));
                $output['benchmark_amount_sen'] = abs($actual);
            }
            if ($actual < 0) {
                $violations[] = 'The chosen benchmark is negative (a loss or net liabilities); the team must judge whether it is appropriate.';
            }
        }

        foreach (['om_percentage' => [0, 100], 'pm_percentage' => [0, 100], 'ct_percentage' => [0, 100]] as $k => [$lo, $hi]) {
            if ($output[$k] <= $lo || $output[$k] > $hi) {
                $violations[] = "{$k} is out of range.";
                $output['usable'] = false;
            }
        }
        if ($output['ct_percentage'] >= $output['pm_percentage']) {
            $violations[] = 'Clearly trivial must be below performance materiality.';
            $output['usable'] = false;
        }

        $om = (int) round($output['benchmark_amount_sen'] * $output['om_percentage'] / 100);
        $output['computed'] = [
            'om_sen' => $om,
            'pm_sen' => (int) round($om * $output['pm_percentage'] / 100),
            'ct_sen' => (int) round($om * $output['ct_percentage'] / 100),
        ];
        $output['specifics'] = array_values(array_filter($output['specifics'], function ($s) use ($om, &$violations) {
            if ($s['amount_sen'] <= 0 || $s['amount_sen'] >= $om) {
                $violations[] = "Specific materiality for \"{$s['area']}\" removed: it must be above zero and below overall materiality.";

                return false;
            }

            return true;
        }));

        $output['citations'] = $this->verifiedCitations($output['citations'], $toolResults, $violations, 'Materiality');
        foreach (['rationale', 'pm_rationale', 'ct_rationale'] as $k) {
            if ($this->prescriptiveClaims($output[$k])) {
                $violations[] = "The {$k} presents a percentage as required by a standard. No standard prescribes these percentages — review the wording.";
            }
        }

        return ['output' => $output, 'violations' => $violations];
    }
}
