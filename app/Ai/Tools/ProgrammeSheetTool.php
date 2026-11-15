<?php

namespace App\Ai\Tools;

/** The programme sheet a "suggest rows" task was started for, with its existing steps. */
class ProgrammeSheetTool implements Tool
{
    public function name(): string
    {
        return 'programme_sheet';
    }

    public function description(): string
    {
        return 'The audit programme sheet this task was started for: reference, title, stage, objective, FSLI and its existing steps (ref, procedure, assertions, risk).';
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
        // The sheet comes from the run's input (chosen by the person who started it), never from the model.
        $sheet = $ctx->engagement->programmeSheets()->where('uuid', $ctx->run->input['subject'] ?? '')->with(['fsli', 'rows'])->first();
        if (! $sheet) {
            return ['error' => 'No programme sheet is attached to this task.'];
        }

        return [
            'sheet' => ['reference' => $sheet->reference, 'title' => $sheet->title, 'stage' => $sheet->stage->value, 'objective' => $sheet->objective,
                'fsli' => $sheet->fsli ? ['code' => $sheet->fsli->code, 'name' => $sheet->fsli->name] : null],
            'steps' => $sheet->rows->map(fn ($r) => ['ref' => $r->ref, 'procedure' => mb_substr($r->procedure, 0, 600), 'assertions' => $r->assertions, 'risk' => $r->risk])->values()->all(),
        ];
    }
}
