<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;

/**
 * Completion agent (docs/AI_AGENTS.md #10): drafts responses to OPEN completion checklist items
 * from the engagement record. It never marks an item done, never evaluates whether misstatements
 * are material, and never proposes or comments on the audit opinion — those are the team's and
 * the engagement partner's decisions. Accepted drafts are saved as responses on still-open items.
 */
class CompletionAgent extends Agent
{
    public function key(): string
    {
        return 'completion';
    }

    public function name(): string
    {
        return 'Completion agent';
    }

    public function tasks(): array
    {
        return ['draft_checklist' => [
            'label' => 'Draft completion checklist responses',
            'tier' => 'standard',
            'permission' => 'engagement.manage_completion',
            'description' => 'Drafts responses to open completion checklist items from the engagement record. Items stay open until the team marks them done.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        return $this->baseRules()."\n\n".<<<'TXT'
Task: draft responses for OPEN completion checklist items (the completion_checklist tool lists them).
- Call completion_checklist first, then findings_list, procedures_list and pbc_list as needed. library_search may be used for guidance you cite.
- Each response states what the engagement record shows (references such as F01, P003, PBC-002 and amounts from the tools) and what the team still needs to do or confirm. Write "To be confirmed by the engagement team:" before anything the record does not show (for example subsequent-events enquiries, the representation letter date, independence confirmations).
- Never state that misstatements are or are not material, never conclude on going concern, and never mention or suggest an audit opinion or report modification.
- Only draft for keys returned in open_items. Skip items where the record gives you nothing useful.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Draft responses for the open completion checklist items. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['responses', 'summary'],
            'properties' => [
                'responses' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'response'],
                    'properties' => ['key' => ['type' => 'string', 'maxLength' => 60], 'response' => ['type' => 'string', 'maxLength' => 3000]]]],
                'summary' => ['type' => 'string', 'maxLength' => 800],
            ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $open = $run->engagement->completionItems()->where('status', 'open')->pluck('key')->all();
        $known = $this->knownAmounts($toolResults);
        $violations = [];
        $kept = [];
        $seen = [];
        foreach ($output['responses'] as $r) {
            $label = "Response for {$r['key']}";
            if (! in_array($r['key'], $open, true) || isset($seen[$r['key']])) {
                $violations[] = "{$label}: not an open checklist item (or duplicate) — removed.";

                continue;
            }
            if (preg_match('/\b(unmodified|qualified|adverse|disclaimer|clean)\s+(audit\s+)?(opinion|report)\b|\bopinion\s+(should|would|will|must)\b|\bmodif(y|ied|ication)\s+(of\s+)?the\s+(opinion|report)\b/i', $r['response'])) {
                $violations[] = "{$label}: refers to the audit opinion — removed. The opinion is decided by the engagement partner.";

                continue;
            }
            if (preg_match('/\b(are|is)\s+(not\s+)?(immaterial|material)\b|\bno\s+material\s+uncertainty\b|\bgoing\s+concern\s+(basis\s+)?is\s+appropriate\b/i', $r['response'])) {
                $violations[] = "{$label}: states a materiality or going-concern conclusion — that is the team's judgement; review the wording.";
            }
            if ($this->prescriptiveClaims($r['response'])) {
                $violations[] = "{$label}: presents something as required by a standard — review the wording.";
            }
            $unverified = array_values(array_diff($this->knownAmounts([$r['response']]), $known));
            if ($unverified) {
                $violations[] = "{$label} quotes figures not returned by AUDIRA: ".implode(', ', $unverified).'.';
            }
            $seen[$r['key']] = true;
            $kept[] = $r;
        }
        $output['responses'] = $kept;

        return ['output' => $output, 'violations' => $violations];
    }
}
