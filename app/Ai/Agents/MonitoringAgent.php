<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;

/**
 * Engagement Monitoring agent (docs/AI_AGENTS.md #12, digest part): turns the daily rule-based
 * alerts and progress counts into a short prioritised digest for the partner and manager.
 * Advisory only: it has no write tools and nothing to accept.
 */
class MonitoringAgent extends Agent
{
    public function key(): string
    {
        return 'monitoring';
    }

    public function name(): string
    {
        return 'Engagement Monitoring agent';
    }

    public function tasks(): array
    {
        return ['health_digest' => [
            'label' => 'Engagement health digest',
            'tier' => 'fast',
            'permission' => 'engagement.view',
            'description' => 'Summarises open alerts and progress into a short list of priorities for the engagement team.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        return $this->baseRules()."\n\n".<<<'TXT'
Task: write a short health digest for the engagement partner and manager.
- Call engagement_health once. Base everything on it.
- summary: two or three sentences on where the engagement stands against its deadline.
- priorities: at most six concrete next actions, most urgent first, each linked to an alert kind when one applies (alert_kind = the alert's kind, else null).
- Do not comment on audit judgements (materiality, risks, findings' significance) or the opinion.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Write the health digest. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'priorities'],
            'properties' => [
                'summary' => ['type' => 'string', 'maxLength' => 800],
                'priorities' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'alert_kind'],
                    'properties' => ['action' => ['type' => 'string', 'maxLength' => 300], 'alert_kind' => ['type' => ['string', 'null'], 'maxLength' => 40]]]],
            ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $kinds = $run->engagement->alerts()->open()->pluck('kind')->all();
        $violations = [];
        $kept = [];
        foreach ($output['priorities'] as $p) {
            if (preg_match('/\bopinion\b/i', $p['action'])) {
                $violations[] = 'A priority referring to the opinion was removed.';

                continue;
            }
            if ($p['alert_kind'] !== null && ! in_array($p['alert_kind'], $kinds, true)) {
                $violations[] = "Unknown alert kind {$p['alert_kind']} cleared.";
                $p['alert_kind'] = null;
            }
            $kept[] = $p;
        }
        $output['priorities'] = $kept;
        if (preg_match('/\bopinion\b/i', $output['summary'])) {
            $violations[] = 'The summary refers to the opinion — review the wording.';
        }

        return ['output' => $output, 'violations' => $violations];
    }
}
