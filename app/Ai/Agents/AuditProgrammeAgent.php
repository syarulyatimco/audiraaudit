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
        return [
            'draft_procedures' => [
                'label' => 'Draft procedures for assessed risks',
                'tier' => 'standard',
                'permission' => 'engagement.manage_programme',
                'description' => 'Proposes further audit procedures that respond to the risks in the register, each traced to its risks and assertions. You choose which to add.',
            ],
            'suggest_rows' => [
                'label' => 'Suggest extra steps for this programme',
                'tier' => 'standard',
                'permission' => 'engagement.manage_programme',
                'description' => 'Reads this programme and the risk register and proposes additional steps for risks the programme does not yet cover. You choose which rows to add.',
            ],
        ];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        if ($task === 'suggest_rows') {
            return $this->baseRules()."\n\n".sprintf('Engagement %s: %s client, framework %s, period ending %s.', $engagement->code,
                $engagement->client->entity_type->label(), $engagement->reporting_framework->label(), $engagement->period_end->toDateString())
                ."\n\n".<<<'TXT'
Task: propose ADDITIONAL steps for one audit programme sheet, in the firm's grid format.
- Call programme_sheet first, then risks_list and fsli_list. Use library_search only for guidance you can cite.
- Each step must respond to at least one risk from risks_list that relates to this sheet's FSLI (or, for planning sheets, to the engagement), given by its reference.
- Prefer risks the existing steps do not address. Never repeat or reword an existing step.
- procedure: one concrete step an audit senior can perform, saying what evidence to obtain. Do not state sample sizes; sampling is planned in AUDIRA.
- assertions: short codes from E, O, C, A, CO, CL, R, V, P (comma separated).
- isa_refs: name the standard only (e.g. "ISA 505"), never paragraph text or numbers you have not retrieved.
- Return at most 8 steps.
TXT;
        }
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
        if ($task === 'suggest_rows') {
            return 'Suggest additional steps for the programme sheet attached to this task. Respond with the JSON object only when finished.';
        }

        return 'Draft procedures for the assessed risks of this engagement. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        if ($task === 'suggest_rows') {
            return ['type' => 'object', 'required' => ['rows', 'summary'], 'additionalProperties' => false, 'properties' => [
                'rows' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['procedure', 'assertions', 'risk_references', 'isa_refs', 'rationale'],
                    'properties' => [
                        'procedure' => ['type' => 'string', 'maxLength' => 1500],
                        'assertions' => ['type' => 'string', 'maxLength' => 40],
                        'risk_references' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string', 'maxLength' => 20]],
                        'isa_refs' => ['type' => 'string', 'maxLength' => 120],
                        'rationale' => ['type' => 'string', 'maxLength' => 400],
                    ],
                ]],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
            ]];
        }

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
        if ($task === 'suggest_rows') {
            return $this->guardRows($run, $output);
        }
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

    /** Rows: real risk references only, valid assertion codes, no repeats of existing steps, no sample sizes. */
    private function guardRows(AiRun $run, array $output): array
    {
        $e = $run->engagement;
        $sheet = $e->programmeSheets()->where('uuid', $run->input['subject'] ?? '')->with('rows')->first();
        $risks = $e->risks()->active()->pluck('reference')->all();
        $existing = $sheet ? $sheet->rows->map(fn ($r) => $this->normal($r->procedure))->filter()->all() : [];
        $codes = ['E', 'O', 'C', 'A', 'CO', 'CL', 'R', 'V', 'P'];
        $violations = [];
        $kept = [];
        if (! $sheet) {
            return ['output' => ['rows' => [], 'summary' => $output['summary']], 'violations' => ['The programme sheet for this task no longer exists.']];
        }
        foreach ($output['rows'] as $i => $r) {
            $label = 'Step '.($i + 1);
            $bad = array_values(array_diff($r['risk_references'], $risks));
            if ($bad) {
                $violations[] = "{$label}: unknown risk reference(s) ".implode(', ', $bad).' removed.';
                $r['risk_references'] = array_values(array_intersect($r['risk_references'], $risks));
            }
            if (! $r['risk_references']) {
                $violations[] = "{$label}: does not respond to any assessed risk — removed.";

                continue;
            }
            $n = $this->normal($r['procedure']);
            if ($n === '' || in_array($n, $existing, true)) {
                $violations[] = "{$label}: repeats an existing step — removed.";

                continue;
            }
            $existing[] = $n;
            $given = collect(preg_split('/[\s,;\/]+/', strtoupper($r['assertions'])))->filter();
            if ($given->diff($codes)->isNotEmpty()) {
                $violations[] = "{$label}: unknown assertion code(s) ".$given->diff($codes)->join(', ').' removed.';
            }
            $r['assertions'] = $given->intersect($codes)->unique()->join(', ');
            if (preg_match('/\b(select|sample|test)\s+(\d{2,4})\s+(items|invoices|transactions|samples|balances)\b/i', $r['procedure'])) {
                $violations[] = "{$label}: states a sample size; sizes come from AUDIRA's sampling plans — review the wording.";
            }
            if (preg_match('/\d+\.\d+|para(graph)?/i', $r['isa_refs'])) {
                $violations[] = "{$label}: ISA paragraph numbers are not verified — reduced to the standard number.";
                $r['isa_refs'] = trim(preg_replace('/\.\d+[A-Za-z]?|,?\s*para(graph)?s?\s*[\d\w\-–, ]*/i', '', $r['isa_refs']));
            }
            $kept[] = $r;
        }
        $output['rows'] = $kept;

        return ['output' => $output, 'violations' => $violations];
    }

    private function normal(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
    }
}
