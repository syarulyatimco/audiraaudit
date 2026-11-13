<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;

/**
 * Findings agent (docs/AI_AGENTS.md #10): drafts findings from sample exceptions and procedure
 * conclusions. Misstatement amounts must be figures AUDIRA computed (exception, factual or
 * projected totals); it never decides whether a finding is significant or a deficiency is
 * significant beyond proposing an evaluation for the auditor, and accepted drafts are recorded as
 * open findings flagged as AI-drafted.
 */
class FindingsAgent extends Agent
{
    public function key(): string
    {
        return 'findings';
    }

    public function name(): string
    {
        return 'Findings agent';
    }

    public function tasks(): array
    {
        return ['draft_findings' => [
            'label' => 'Draft findings',
            'tier' => 'standard',
            'permission' => 'engagement.manage_findings',
            'description' => 'Drafts misstatement and control-deficiency findings from sample exceptions and completed procedures, using amounts AUDIRA computed. Nothing is recorded until an auditor accepts it.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        return $this->baseRules()."\n\n".<<<'TXT'
Task: draft findings that are not yet in the findings register.
- Call sample_exceptions, findings_list, procedures_list, fsli_list and materiality_current.
- Monetary samples: propose a "factual" misstatement equal to factual_misstatement_sen and, if the sample was evaluated and projected_remainder_sen is not zero, a "projected" misstatement equal to projected_remainder_sen. Use plan_reference and the plan's fsli_code. Skip any sample whose findings_recorded already covers it.
- amount_sen = recorded minus audited (positive = overstatement). Use only amounts returned by the tools; never compute new amounts.
- Control (attribute) samples with deviations, or procedure conclusions describing a control failure: propose a control_deficiency with a proposed deficiency_severity — the auditor evaluates it.
- Judgemental misstatements (estimates, policies) only when a procedure conclusion states the auditor's own estimate and amount; otherwise do not propose them.
- Write a factual description (condition), and where the data supports it, cause and recommendation. Do not speculate about fraud or intent.
- Never say whether a finding is significant or material, and never comment on the audit opinion.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Draft the findings for this engagement. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['findings', 'summary'],
            'properties' => [
                'findings' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['type', 'title', 'description', 'cause', 'recommendation', 'fsli_code', 'procedure_reference', 'plan_reference', 'misstatement_class', 'amount_sen', 'deficiency_severity'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['misstatement', 'control_deficiency', 'other']],
                        'title' => ['type' => 'string', 'maxLength' => 200],
                        'description' => ['type' => 'string', 'maxLength' => 3000],
                        'cause' => ['type' => ['string', 'null'], 'maxLength' => 1500],
                        'recommendation' => ['type' => ['string', 'null'], 'maxLength' => 1500],
                        'fsli_code' => ['type' => ['string', 'null'], 'maxLength' => 20],
                        'procedure_reference' => ['type' => ['string', 'null'], 'maxLength' => 20],
                        'plan_reference' => ['type' => ['string', 'null'], 'maxLength' => 20],
                        'misstatement_class' => ['type' => ['string', 'null'], 'enum' => ['factual', 'judgemental', 'projected', null]],
                        'amount_sen' => ['type' => ['integer', 'null']],
                        'deficiency_severity' => ['type' => ['string', 'null'], 'enum' => ['deficiency', 'significant_deficiency', null]],
                    ]]],
                'summary' => ['type' => 'string', 'maxLength' => 800],
            ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $e = $run->engagement;
        $violations = [];
        $fslis = $e->fslis()->pluck('code')->all();
        $procs = $e->procedures()->active()->pluck('reference')->all();
        $plans = $e->samplingPlans()->pluck('reference')->all();

        // Amounts AUDIRA computed and showed the model, and samples already covered by findings.
        $amounts = [];
        $samples = [];
        foreach ($toolResults as $r) {
            foreach ($r['samples'] ?? [] as $s) {
                $samples[$s['plan_reference']] = $s;
            }
        }
        foreach ($samples as $ref => $s) {
            foreach ($s['exceptions'] as $x) {
                if ($x['misstatement_sen']) {
                    $amounts[$ref][] = $x['misstatement_sen'];
                }
            }
            foreach (['factual_misstatement_sen', 'projected_remainder_sen'] as $k) {
                if ($s[$k]) {
                    $amounts[$ref][] = $s[$k];
                }
            }
        }
        $recorded = $e->findings()->active()->get();

        $kept = [];
        foreach ($output['findings'] as $i => $f) {
            $label = 'Finding '.($i + 1).' ('.$f['title'].')';
            foreach (['fsli_code' => $fslis, 'procedure_reference' => $procs, 'plan_reference' => $plans] as $k => $valid) {
                if ($f[$k] !== null && ! in_array($f[$k], $valid, true)) {
                    $violations[] = "{$label}: unknown {$k} {$f[$k]} cleared.";
                    $f[$k] = null;
                }
            }
            if ($this->prescriptiveClaims($f['description'].' '.($f['recommendation'] ?? ''))) {
                $violations[] = "{$label}: presents something as required by a standard — review the wording.";
            }
            if (preg_match('/\b(is|are)\s+(not\s+)?(material|significant)\b|\bopinion\b/i', $f['description'])) {
                $violations[] = "{$label}: comments on materiality, significance or the opinion — those are the auditor's judgements; review the wording.";
            }

            if ($f['type'] === 'misstatement') {
                if (! $f['misstatement_class'] || ! $f['amount_sen'] || ! $f['fsli_code']) {
                    $violations[] = "{$label}: a misstatement needs a class, an amount and a known line item — removed.";

                    continue;
                }
                $verified = $f['plan_reference'] && in_array($f['amount_sen'], $amounts[$f['plan_reference']] ?? [], true);
                if ($f['misstatement_class'] !== 'judgemental' && ! $verified) {
                    $violations[] = "{$label}: amount ".amount($f['amount_sen'], false).' does not match a figure AUDIRA computed for the sample — removed.';

                    continue;
                }
                if ($f['misstatement_class'] === 'judgemental' && ! $verified) {
                    $violations[] = "{$label}: judgemental amount could not be traced to AUDIRA data — the auditor must verify it before accepting.";
                }
                if ($f['plan_reference'] && $recorded->contains(fn ($r) => $r->samplingPlan?->reference === $f['plan_reference'] && $r->misstatement_class?->value === $f['misstatement_class'])) {
                    $violations[] = "{$label}: a {$f['misstatement_class']} finding from {$f['plan_reference']} is already recorded — removed.";

                    continue;
                }
                $f['deficiency_severity'] = null;
            } else {
                $f['misstatement_class'] = null;
                $f['amount_sen'] = null;
                if ($f['type'] === 'control_deficiency' && ! $f['deficiency_severity']) {
                    $f['deficiency_severity'] = 'deficiency';
                }
                if ($f['type'] !== 'control_deficiency') {
                    $f['deficiency_severity'] = null;
                }
            }
            $kept[] = $f;
        }
        $output['findings'] = $kept;

        return ['output' => $output, 'violations' => $violations];
    }
}
