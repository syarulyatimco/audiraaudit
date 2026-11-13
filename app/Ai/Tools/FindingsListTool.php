<?php

namespace App\Ai\Tools;

/** The engagement's findings register (active findings). */
class FindingsListTool implements Tool
{
    public function name(): string
    {
        return 'findings_list';
    }

    public function description(): string
    {
        return 'Active findings already recorded for this engagement: reference, type, title, FSLI code, procedure and sample references, misstatement class and amount in sen, deficiency severity, correction and status.';
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
        return ['findings' => $ctx->engagement->findings()->active()->with(['fsli', 'procedure', 'samplingPlan'])->get()->map(fn ($f) => [
            'reference' => $f->reference, 'type' => $f->type->value, 'title' => $f->title, 'fsli_code' => $f->fsli?->code,
            'procedure_reference' => $f->procedure?->reference, 'plan_reference' => $f->samplingPlan?->reference,
            'misstatement_class' => $f->misstatement_class?->value, 'amount_sen' => $f->amount_sen,
            'deficiency_severity' => $f->deficiency_severity?->value, 'correction_status' => $f->correction_status?->value, 'status' => $f->status->value,
        ])->all()];
    }
}
