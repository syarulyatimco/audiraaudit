<?php

namespace App\Ai\Tools;

use App\Enums\ReviewNoteStatus;

/** Working-paper review status of the engagement for a quality pre-review. */
class ReviewStatusTool implements Tool
{
    public function name(): string
    {
        return 'review_status';
    }

    public function description(): string
    {
        return 'Every working paper (reference, title, section, status, whether objective / work performed / conclusion are filled, evidence documents linked, open review notes) and every active procedure (reference, status, linked working paper, conclusion present).';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function requiredPermission(): string
    {
        return 'engagement.view';
    }

    public function handle(ToolContext $ctx, array $input): array
    {
        $e = $ctx->engagement;

        return [
            'working_papers' => $e->workingPapers()->withCount(['documents', 'reviewNotes as open_notes' => fn ($q) => $q->where('status', '!=', ReviewNoteStatus::Cleared->value)])->orderBy('reference')->get()
                ->map(fn ($w) => ['reference' => $w->reference, 'title' => $w->title, 'section' => $w->section->value, 'status' => $w->status->value,
                    'has_objective' => filled($w->objective), 'has_work_performed' => filled($w->work_performed), 'has_conclusion' => filled($w->conclusion),
                    'evidence_documents' => $w->documents_count, 'open_review_notes' => $w->open_notes, 'system_paper' => $w->system_key !== null])->all(),
            'procedures' => $e->procedures()->active()->with('workingPaper')->get()
                ->map(fn ($p) => ['reference' => $p->reference, 'title' => $p->title, 'status' => $p->status->value, 'working_paper' => $p->workingPaper?->reference, 'has_conclusion' => filled($p->conclusion)])->all(),
        ];
    }
}
