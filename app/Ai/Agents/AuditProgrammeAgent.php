<?php

namespace App\Ai\Agents;

use App\Enums\Assertion;
use App\Enums\ProcedureNature;
use App\Models\AiRun;
use App\Models\Engagement;

/**
 * Audit Programme agent (docs/AI_AGENTS.md #6): drafts procedures responsive to assessed risks.
 * Accepted procedures enter the programme as drafts; the programme paper is signed by people.
 */
class AuditProgrammeAgent extends Agent
{
    public function key(): string
    {
        return 'audit_programme';
    }

    public function name(): string
    {
        return 'Audit Programme agent';
    }

    public function tasks(): array
    {
        return ['draft_procedures' => [
            'label' => 'Draft procedures for assessed risks',
            'tier' => 'standard',
            'permission' => 'engagement.manage_programme',
            'description' => 'Proposes further audit procedures that respond to the risks in the register, each traced to its risks and assertions. You choose which to add.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        $natures = implode(', ', ProcedureNature::values());
        $assertions = implode(', ', Assertion::values());

        return $this->baseRules()."\n\n".sprintf('Engagement %s: %s, %s client, framework %s, period ending %s.', $engagement->code,
            $engagement->engagement_type->label(), $engagement->client->entity_type->label(), $engagement->reporting_framework->label(), $engagement->period_end->toDateString())
            ."\n\n".<<<TXT
Task: draft further audit procedures that respond to the assessed risks of material misstatement.
- Call risks_list, fsli_list, materiality_current and procedures_list. Use library_search for guidance you can cite.
- Every procedure must respond to at least one risk by its reference from risks_list. Prioritise risks that have no procedure yet in procedures_list, and significant risks (which need procedures specifically responsive to them, including substantive procedures).
- nature from: {$natures}. assertions from: {$assertions}. fsli_code from fsli_list or null.
- steps: concrete, numbered steps an audit senior can perform, including what evidence to obtain. Do not set sample sizes: sampling is planned separately with AUDIRA's documented methods.
- Do not repeat procedures already in procedures_list. Return at most 15 procedures.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Draft procedures for the assessed risks of this engagement. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'required' => ['procedures', 'summary'], 'additionalProperties' => false, 'properties' => [
            'procedures' => ['type' => 'array', 'maxItems' => 15, 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['risk_references', 'fsli_code', 'title', 'steps', 'nature', 'timing', 'assertions', 'citations'],
                'properties' => [
                    'risk_references' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 20]],
                    'fsli_code' => ['type' => ['string', 'null'], 'maxLength' => 20],
                    'title' => ['type' => 'string', 'maxLength' => 200],
                    'steps' => ['type' => 'string', 'maxLength' => 4000],
                    'nature' => ['type' => 'string', 'enum' => ProcedureNature::values()],
                    'timing' => ['type' => 'string', 'enum' => ['interim', 'final', 'roll_forward']],
                    'assertions' => ['type' => 'array', 'maxItems' => 9, 'items' => ['type' => 'string', 'enum' => Assertion::values()]],
                    'citations' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'object', 'required' => ['paragraph_id', 'relevance'], 'additionalProperties' => false,
                        'properties' => ['paragraph_id' => ['type' => 'integer'], 'relevance' => ['type' => 'string', 'maxLength' => 300]]]],
                ],
            ]],
            'summary' => ['type' => 'string', 'maxLength' => 1000],
        ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $e = $run->engagement;
        $risks = $e->risks()->active()->pluck('reference')->all();
        $fslis = $e->fslis()->pluck('code')->all();
        $existing = $e->procedures()->active()->pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->all();
        $violations = [];
        $kept = [];
        foreach ($output['procedures'] as $i => $p) {
            $label = 'Procedure '.($i + 1).' ('.$p['title'].')';
            $bad = array_values(array_diff($p['risk_references'], $risks));
            if ($bad) {
                $violations[] = "{$label}: unknown risk reference(s) ".implode(', ', $bad).' removed.';
                $p['risk_references'] = array_values(array_intersect($p['risk_references'], $risks));
            }
            if (! $p['risk_references']) {
                $violations[] = "{$label}: does not respond to any assessed risk — removed.";

                continue;
            }
            if ($p['fsli_code'] !== null && ! in_array($p['fsli_code'], $fslis, true)) {
                $violations[] = "{$label}: unknown FSLI {$p['fsli_code']} cleared.";
                $p['fsli_code'] = null;
            }
            if (in_array(mb_strtolower(trim($p['title'])), $existing, true)) {
                $violations[] = "{$label}: already in the programme — removed.";

                continue;
            }
            if (preg_match('/\b(select|sample|test)\s+(\d{2,4})\s+(items|invoices|transactions|samples)\b/i', $p['steps'])) {
                $violations[] = "{$label}: states a sample size; sizes come from AUDIRA's sampling plans — review the wording.";
            }
            $p['citations'] = $this->verifiedCitations($p['citations'], $toolResults, $violations, $label);
            $kept[] = $p;
        }
        $output['procedures'] = $kept;

        return ['output' => $output, 'violations' => $violations];
    }
}
