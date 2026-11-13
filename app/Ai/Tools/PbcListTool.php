<?php

namespace App\Ai\Tools;

class PbcListTool implements Tool
{
    public function name(): string
    {
        return 'pbc_list';
    }

    public function description(): string
    {
        return 'Client document requests (PBC items) for this engagement: reference, title, category, status, due date and whether overdue.';
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
        return ['today' => now()->toDateString(), 'items' => $ctx->engagement->pbcItems()->whereNot('status', 'cancelled')->get()->map(fn ($i) => [
            'reference' => $i->reference, 'title' => $i->title, 'description' => $i->description, 'category' => $i->category->value,
            'status' => $i->status->value, 'due_on' => $i->due_on?->toDateString(), 'overdue' => $i->isOverdue(), 'returned_note' => $i->review_note,
        ])->all()];
    }
}
