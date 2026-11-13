<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;

/**
 * Quality Review agent (docs/AI_AGENTS.md #11): an advisory pre-review of the file before the
 * reviewer or EQR. It drafts review notes on specific working papers; notes are raised only when a
 * person accepts them, in that person's name. It cannot sign, clear notes or approve anything.
 */
class QualityReviewAgent extends Agent
{
    public function key(): string
    {
        return 'quality_review';
    }

    public function name(): string
    {
        return 'Quality Review agent';
    }

    public function tasks(): array
    {
        return ['pre_review' => [
            'label' => 'Quality pre-review',
            'tier' => 'fast',
            'permission' => 'engagement.view',
            'description' => 'Checks working papers and procedures for missing sign-offs, open review notes, missing conclusions and unlinked evidence, and drafts review notes for a person to raise.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        return $this->baseRules()."\n\n".<<<'TXT'
Task: pre-review the audit file (the review_status tool returns it) and draft review notes.
- Call review_status once.
- Draft a note only for a concrete gap on a specific working paper: no conclusion or work performed recorded, no evidence linked on a fieldwork paper, not yet prepared or reviewed, or a procedure that is completed without a working paper (attach that note to the paper of the procedure if there is one, otherwise list it in "file_observations").
- Do not repeat gaps already covered by open review notes. Do not judge the quality of the audit work itself or the opinion.
- Each note: the working paper reference and a short, specific request (one or two sentences).
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Pre-review the file. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['notes', 'file_observations', 'summary'],
            'properties' => [
                'notes' => ['type' => 'array', 'maxItems' => 40, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['working_paper', 'note'],
                    'properties' => ['working_paper' => ['type' => 'string', 'maxLength' => 20], 'note' => ['type' => 'string', 'maxLength' => 600]]]],
                'file_observations' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 400]],
                'summary' => ['type' => 'string', 'maxLength' => 800],
            ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $papers = $run->engagement->workingPapers()->pluck('reference')->all();
        $violations = [];
        $kept = [];
        foreach ($output['notes'] as $n) {
            if (! in_array($n['working_paper'], $papers, true)) {
                $violations[] = "Note on unknown working paper {$n['working_paper']} removed.";

                continue;
            }
            if (preg_match('/\b(opinion|sign(ed)?\s+off\s+the\s+report)\b/i', $n['note'])) {
                $violations[] = "Note on {$n['working_paper']} refers to the opinion — removed.";

                continue;
            }
            $kept[] = $n;
        }
        $output['notes'] = $kept;

        return ['output' => $output, 'violations' => $violations];
    }
}
