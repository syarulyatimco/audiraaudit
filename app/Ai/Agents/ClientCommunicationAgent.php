<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;

/**
 * Client Communication agent (docs/AI_AGENTS.md #1): drafts PBC requests and reminders. Drafts
 * never reach the client by themselves — a team member edits and sends them (ROADMAP risk #2).
 */
class ClientCommunicationAgent extends Agent
{
    public function key(): string
    {
        return 'client_communication';
    }

    public function name(): string
    {
        return 'Client Communication agent';
    }

    public function tasks(): array
    {
        return ['draft_request_message' => [
            'label' => 'Draft a request or reminder to the client',
            'tier' => 'fast',
            'permission' => 'engagement.message_client',
            'description' => 'Drafts a courteous portal message listing outstanding and overdue requests. It is saved as a draft for you to edit and send.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        return $this->baseRules()."\n\n".<<<'TXT'
Task: draft ONE portal message from the audit team to the client about outstanding document requests.
- Call pbc_list and engagement_overview.
- Cover items with status "requested" or "returned": overdue items first (politely), then items due soon. For returned items, restate what the team needs using returned_note.
- Refer to items by their PBC reference and title. Do not invent requests, dates or amounts.
- Professional, concise, friendly British/Malaysian business English. Sign off with the sender's name from engagement_overview.
- Never share audit findings, risks, materiality, conclusions or opinions, and never make commitments about fees or deadlines beyond the dates provided.
- pbc_references: the references you mention.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Draft the message. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'required' => ['subject', 'body', 'pbc_references'], 'additionalProperties' => false, 'properties' => [
            'subject' => ['type' => 'string', 'maxLength' => 150],
            'body' => ['type' => 'string', 'maxLength' => 5000],
            'pbc_references' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string', 'maxLength' => 20]],
        ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $violations = [];
        $open = $run->engagement->pbcItems()->whereIn('status', ['requested', 'returned'])->pluck('reference')->all();
        $all = $run->engagement->pbcItems()->pluck('reference')->all();
        $bad = array_values(array_diff($output['pbc_references'], $all));
        if ($bad) {
            $violations[] = 'Unknown request reference(s) removed: '.implode(', ', $bad).'.';
        }
        $output['pbc_references'] = array_values(array_intersect($output['pbc_references'], $open));
        preg_match_all('/PBC-\d{3}/', $output['body'], $m);
        $invented = array_values(array_diff(array_unique($m[0]), $all));
        if ($invented) {
            $violations[] = 'The message mentions requests that do not exist: '.implode(', ', $invented).'.';
        }
        if (preg_match('/\b(materiality|significant risk|audit opinion|unqualified|qualified opinion|we have concluded|misstatement|finding)s?\b/i', $output['body'])) {
            $violations[] = 'The draft mentions audit judgements (risks, materiality, findings or opinions) that should not be shared with the client this way.';
        }

        return ['output' => $output, 'violations' => $violations];
    }
}
