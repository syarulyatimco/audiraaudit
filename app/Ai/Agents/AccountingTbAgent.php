<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;
use App\Services\TrialBalance\LeadScheduleService;

/**
 * Accounting & Trial Balance agent (docs/AI_AGENTS.md #3).
 *  - suggest_mappings: propose account → FSLI mappings for unmapped accounts (accepted by a person)
 *  - review_tb: analytical observations on the mapped TB for the auditor's consideration
 */
class AccountingTbAgent extends Agent
{
    public function __construct(private LeadScheduleService $schedules) {}

    public function key(): string
    {
        return 'accounting_tb';
    }

    public function name(): string
    {
        return 'Accounting & TB agent';
    }

    public function tasks(): array
    {
        return [
            'suggest_mappings' => [
                'label' => 'Suggest account mappings',
                'tier' => 'standard',
                'permission' => 'engagement.map_accounts',
                'description' => 'Proposes an FSLI for each unmapped account, with confidence and rationale. Nothing is mapped until a team member accepts.',
            ],
            'review_tb' => [
                'label' => 'Analytical review of the trial balance',
                'tier' => 'standard',
                'permission' => 'engagement.edit_working_papers',
                'description' => 'Highlights significant fluctuations, opposite balances and unusual items, with suggested procedures, using AUDIRA-computed figures.',
            ],
        ];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        $context = sprintf('Engagement %s: %s, %s client, reporting framework %s, period %s to %s.',
            $engagement->code, $engagement->engagement_type->label(), $engagement->client->entity_type->label(),
            $engagement->reporting_framework->label(), $engagement->period_start->toDateString(), $engagement->period_end->toDateString());

        $taskRules = match ($task) {
            'suggest_mappings' => <<<'TXT'
Task: propose a financial statement line item (FSLI) for each UNMAPPED trial balance account.
- Call fsli_list first, then tb_list_accounts with filter "unmapped". Use mapping_history for prior-year evidence.
- Use only FSLI codes returned by fsli_list. Never suggest an FSLI for an account that is already mapped.
- Consider the sign of the balance (debit positive). Accounts whose presentation needs judgement you cannot resolve from the data (for example borrowings needing a current/non-current split, overdrafts, deferred tax, related-party balances of unclear nature) go in "needs_judgement" with the reason instead of a guess.
- confidence: "high" only when the account name and balance clearly indicate the FSLI; otherwise "medium" or "low".
- rationale: one short sentence an auditor can check.
TXT,
            'review_tb' => <<<'TXT'
Task: perform a preliminary analytical review of the trial balance for the audit team.
- Call tb_analytics. Base every observation on its figures; quote amounts exactly as returned (RM) and do not calculate new amounts.
- Look for: significant changes against the prior year, balances on the opposite side to their FSLI, unmapped accounts, relationships that look inconsistent (for example revenue vs receivables, cost of sales vs inventories).
- severity reflects how much audit attention the item may need; it is not a materiality judgement.
- suggested_procedure: a concrete audit procedure the team could consider. Do not cite standards.
- Return at most 15 observations, most important first.
TXT,
        };

        return $this->baseRules()."\n\n".$context."\n\n".$taskRules;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return match ($task) {
            'suggest_mappings' => 'Propose mappings for the unmapped accounts of this engagement. Respond with the JSON object only when finished.',
            'review_tb' => 'Perform the preliminary analytical review. Respond with the JSON object only when finished.',
        };
    }

    public function outputSchema(string $task): array
    {
        return match ($task) {
            'suggest_mappings' => [
                'type' => 'object', 'required' => ['suggestions', 'needs_judgement', 'summary'], 'additionalProperties' => false,
                'properties' => [
                    'suggestions' => ['type' => 'array', 'maxItems' => 2000, 'items' => [
                        'type' => 'object', 'required' => ['account_code', 'fsli_code', 'confidence', 'rationale'], 'additionalProperties' => false,
                        'properties' => [
                            'account_code' => ['type' => 'string', 'maxLength' => 50],
                            'fsli_code' => ['type' => 'string', 'maxLength' => 20],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'rationale' => ['type' => 'string', 'maxLength' => 300],
                        ],
                    ]],
                    'needs_judgement' => ['type' => 'array', 'maxItems' => 2000, 'items' => [
                        'type' => 'object', 'required' => ['account_code', 'reason'], 'additionalProperties' => false,
                        'properties' => ['account_code' => ['type' => 'string', 'maxLength' => 50], 'reason' => ['type' => 'string', 'maxLength' => 300]],
                    ]],
                    'summary' => ['type' => 'string', 'maxLength' => 1000],
                ],
            ],
            'review_tb' => [
                'type' => 'object', 'required' => ['observations', 'summary'], 'additionalProperties' => false,
                'properties' => [
                    'observations' => ['type' => 'array', 'maxItems' => 15, 'items' => [
                        'type' => 'object', 'required' => ['fsli_code', 'category', 'severity', 'observation', 'suggested_procedure'], 'additionalProperties' => false,
                        'properties' => [
                            'fsli_code' => ['type' => ['string', 'null'], 'maxLength' => 20],
                            'category' => ['type' => 'string', 'enum' => ['fluctuation', 'opposite_balance', 'unmapped', 'relationship', 'other']],
                            'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'observation' => ['type' => 'string', 'maxLength' => 600],
                            'suggested_procedure' => ['type' => 'string', 'maxLength' => 400],
                        ],
                    ]],
                    'summary' => ['type' => 'string', 'maxLength' => 1000],
                ],
            ],
        };
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $engagement = $run->engagement;
        $fslis = $engagement->fslis()->pluck('code')->all();
        $violations = [];

        if ($task === 'suggest_mappings') {
            $unmapped = $this->schedules->accounts($engagement)->whereNull('fsli_id')->keys()->map(fn ($k) => (string) $k)->all();
            $seen = [];
            $kept = [];
            foreach ($output['suggestions'] as $s) {
                $why = match (true) {
                    ! in_array($s['account_code'], $unmapped, true) => 'account is not an unmapped account of this engagement',
                    ! in_array($s['fsli_code'], $fslis, true) => "FSLI {$s['fsli_code']} does not exist in this engagement",
                    isset($seen[$s['account_code']]) => 'duplicate suggestion',
                    default => null,
                };
                if ($why) {
                    $violations[] = "Suggestion for {$s['account_code']} rejected: {$why}.";

                    continue;
                }
                $seen[$s['account_code']] = true;
                $kept[] = $s;
            }
            $output['suggestions'] = $kept;
            $output['needs_judgement'] = array_values(array_filter($output['needs_judgement'], fn ($n) => in_array($n['account_code'], $unmapped, true)));

            return ['output' => $output, 'violations' => $violations];
        }

        // review_tb: references must exist; quoted figures must come from tool data.
        $known = $this->numbersIn($toolResults);
        foreach ($output['observations'] as $i => $o) {
            if ($o['fsli_code'] !== null && ! in_array($o['fsli_code'], $fslis, true)) {
                $violations[] = "Observation ".($i + 1).": FSLI {$o['fsli_code']} does not exist.";
                $output['observations'][$i]['fsli_code'] = null;
            }
            $unsupported = array_values(array_diff($this->numbersIn([$o['observation']]), $known));
            $output['observations'][$i]['unverified_figures'] = $unsupported;
            if ($unsupported) {
                $violations[] = 'Observation '.($i + 1).' quotes figures not returned by AUDIRA: '.implode(', ', $unsupported).'.';
            }
        }

        return ['output' => $output, 'violations' => $violations];
    }

    /**
     * Amounts of 1,000 or more appearing in data, normalised to whole ringgit strings.
     *
     * @return list<string>
     */
    private function numbersIn(array $data): array
    {
        $out = [];
        array_walk_recursive($data, function ($v) use (&$out) {
            if (is_int($v) || is_float($v)) {
                if (abs($v) >= 1000) {
                    $out[] = (string) (int) round(abs($v));
                }

                return;
            }
            if (is_string($v) && preg_match_all('/(?<![\d.])\d{1,3}(?:,\d{3})+(?:\.\d+)?|(?<![\d.,])\d{4,}(?:\.\d+)?/', $v, $m)) {
                foreach ($m[0] as $n) {
                    if (preg_match('/^(19|20)\d{2}$/', $n)) {
                        continue; // a year, not an amount
                    }
                    $f = (float) str_replace(',', '', $n);
                    if ($f >= 1000) {
                        $out[] = (string) (int) round($f);
                    }
                }
            }
        });

        return array_values(array_unique($out));
    }
}
