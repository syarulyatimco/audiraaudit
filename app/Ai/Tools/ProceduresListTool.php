<?php

namespace App\Ai\Tools;

class ProceduresListTool implements Tool
{
    public function name(): string
    {
        return 'procedures_list';
    }

    public function description(): string
    {
        return 'Procedures already in the audit programme (active only), with the risk references they respond to, so you do not propose duplicates.';
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
        return ['procedures' => $ctx->engagement->procedures()->active()->with(['risks', 'fsli'])->get()->map(fn ($p) => [
            'reference' => $p->reference, 'title' => $p->title, 'nature' => $p->nature->value, 'fsli_code' => $p->fsli?->code,
            'risk_references' => $p->risks->pluck('reference')->all(),
        ])->all()];
    }
}
