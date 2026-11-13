<?php

namespace App\Ai\Tools;

use App\Models\Risk;

class RisksListTool implements Tool
{
    public function name(): string
    {
        return 'risks_list';
    }

    public function description(): string
    {
        return 'Risks already recorded in this engagement\'s risk register (active only), so you do not propose duplicates.';
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
        return ['risks' => $ctx->engagement->risks()->active()->with('fslis')->get()->map(fn (Risk $r) => [
            'reference' => $r->reference, 'title' => $r->title, 'level' => $r->level->value,
            'fsli_codes' => $r->fslis->pluck('code')->all(), 'assertions' => $r->assertions,
            'significant' => $r->is_significant, 'fraud' => $r->is_fraud_risk,
        ])->all()];
    }
}
