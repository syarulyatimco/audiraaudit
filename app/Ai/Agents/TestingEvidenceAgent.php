<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Engagement;
use App\Models\SamplingPlan;

/**
 * Testing & Evidence agent (docs/AI_AGENTS.md #9): drafts results for the untested items of one
 * sample by comparing each item with the engagement documents that mention it. Every drafted
 * result must quote the evidence verbatim, and the amount it relies on must appear in that
 * evidence. Results are only recorded when an auditor accepts them — and the accepting auditor
 * becomes the tester of record.
 */
class TestingEvidenceAgent extends Agent
{
    public function key(): string
    {
        return 'testing_evidence';
    }

    public function name(): string
    {
        return 'Testing & Evidence agent';
    }

    public function tasks(): array
    {
        return ['draft_test_results' => [
            'label' => 'Draft test results from evidence',
            'tier' => 'standard',
            'permission' => 'engagement.execute_procedures',
            'description' => 'Compares each untested sample item with the documents that mention it and drafts a result, quoting the evidence. An auditor reviews and accepts each result.',
        ]];
    }

    public function systemPrompt(string $task, Engagement $engagement): string
    {
        return $this->baseRules()."\n\n".<<<'TXT'
Task: draft test results for the items of ONE sample (the sample_evidence tool returns them with candidate evidence excerpts).
- Call sample_evidence once. For each item decide only from its candidate excerpts.
- Only draft a result for an item when an excerpt clearly relates to that item. Otherwise put the item_ref in "no_evidence" and do not guess.
- evidence_quote: copy, character for character, the shortest passage of the excerpt that supports your result (it must include the amount you relied on for monetary tests).
- Monetary tests: "no_exception" when the evidence supports the recorded amount; "exception" when it shows a different amount — give audited_amount_sen (integer sen) from the evidence. For attribute (control) tests audited_amount_sen is null.
- document_id must be the document_id of the excerpt you quote.
- note: one or two sentences describing what you compared. Never claim you inspected anything other than the excerpt.
TXT;
    }

    public function instruction(string $task, Engagement $engagement, array $input): string
    {
        return 'Draft results for the untested items of the sample attached to this task. Respond with the JSON object only when finished.';
    }

    public function outputSchema(string $task): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['results', 'no_evidence', 'summary'],
            'properties' => [
                'results' => ['type' => 'array', 'maxItems' => 40, 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['item_ref', 'result', 'audited_amount_sen', 'document_id', 'evidence_quote', 'note'],
                    'properties' => [
                        'item_ref' => ['type' => 'string', 'maxLength' => 100],
                        'result' => ['type' => 'string', 'enum' => ['no_exception', 'exception']],
                        'audited_amount_sen' => ['type' => ['integer', 'null']],
                        'document_id' => ['type' => 'string', 'maxLength' => 40],
                        'evidence_quote' => ['type' => 'string', 'maxLength' => 600],
                        'note' => ['type' => 'string', 'maxLength' => 600],
                    ]]],
                'no_evidence' => ['type' => 'array', 'maxItems' => 40, 'items' => ['type' => 'string', 'maxLength' => 100]],
                'summary' => ['type' => 'string', 'maxLength' => 800],
            ]];
    }

    public function guard(string $task, AiRun $run, array $output, array $toolResults): array
    {
        $violations = [];
        $plan = SamplingPlan::where('engagement_id', $run->engagement_id)->where('uuid', $run->input['subject'] ?? '')->first();
        $monetary = $plan?->test_type?->value === 'monetary';

        // What the model was actually shown: item_ref => [recorded amount, document_id => excerpt]
        $seen = [];
        foreach ($toolResults as $r) {
            foreach (($r['sample_evidence'] ?? false) ? $r['items'] : [] as $it) {
                $seen[$it['item_ref']] = ['recorded' => $it['recorded_amount_sen'], 'docs' => collect($it['candidates'])->pluck('untrusted_excerpt', 'document_id')->all()];
            }
        }

        $kept = [];
        $done = [];
        foreach ($output['results'] as $i => $r) {
            $label = 'Result '.($i + 1).' ('.$r['item_ref'].')';
            $item = $seen[$r['item_ref']] ?? null;
            if (! $item) {
                $violations[] = "{$label}: not an untested item of this sample — removed.";

                continue;
            }
            if (isset($done[$r['item_ref']])) {
                $violations[] = "{$label}: duplicate — removed.";

                continue;
            }
            $excerpt = $item['docs'][$r['document_id']] ?? null;
            if ($excerpt === null) {
                $violations[] = "{$label}: cites a document that was not offered as evidence for this item — removed.";

                continue;
            }
            if (! $this->contains($excerpt, $r['evidence_quote'])) {
                $violations[] = "{$label}: the quoted evidence does not appear in the document — removed.";

                continue;
            }
            if ($monetary) {
                $amount = $r['result'] === 'exception' ? $r['audited_amount_sen'] : $item['recorded'];
                if ($amount === null) {
                    $violations[] = "{$label}: an exception needs the audited amount — removed.";

                    continue;
                }
                if ($r['result'] === 'exception' && $amount === $item['recorded']) {
                    $violations[] = "{$label}: the audited amount equals the recorded amount, so it is not an exception — removed.";

                    continue;
                }
                if (! $this->mentionsAmount($r['evidence_quote'], $amount)) {
                    $violations[] = "{$label}: the amount relied on (".amount($amount, false).') is not in the quoted evidence — removed.';

                    continue;
                }
            } else {
                $r['audited_amount_sen'] = null;
            }
            $done[$r['item_ref']] = true;
            $kept[] = $r;
        }
        $output['results'] = $kept;
        $output['no_evidence'] = array_values(array_intersect($output['no_evidence'], array_keys($seen)));
        if (! $plan) {
            $violations[] = 'The sampling plan for this task no longer exists.';
            $output['results'] = [];
        }

        return ['output' => $output, 'violations' => $violations];
    }

    private function contains(string $haystack, string $needle): bool
    {
        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));

        return $norm($needle) !== '' && str_contains($norm($haystack), $norm($needle));
    }

    /** True when the text shows the amount in a common written form (1,234.56 / 1234.56 / 1,234 for whole ringgit). */
    private function mentionsAmount(string $text, int $sen): bool
    {
        $abs = abs($sen);
        $forms = [number_format($abs / 100, 2, '.', ','), number_format($abs / 100, 2, '.', '')];
        if ($abs % 100 === 0) {
            $forms[] = number_format($abs / 100, 0, '.', ',');
            $forms[] = (string) intdiv($abs, 100);
        }
        foreach ($forms as $f) {
            if (preg_match('/(?<![\d.,])'.preg_quote($f, '/').'(?![\d]|[.,]\d)/', $text)) {
                return true;
            }
        }

        return false;
    }
}
