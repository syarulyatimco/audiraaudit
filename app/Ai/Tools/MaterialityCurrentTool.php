<?php

namespace App\Ai\Tools;

class MaterialityCurrentTool implements Tool
{
    public function name(): string
    {
        return 'materiality_current';
    }

    public function description(): string
    {
        return 'The approved materiality in force for this engagement (amounts in sen), or null if none is approved yet.';
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
        $m = $ctx->engagement->currentMateriality();

        return ['materiality' => $m ? [
            'version' => $m->version, 'benchmark' => $m->benchmark->value, 'benchmark_amount_sen' => $m->benchmark_amount_sen,
            'overall_sen' => $m->om_sen, 'performance_sen' => $m->pm_sen, 'clearly_trivial_sen' => $m->ct_sen,
        ] : null];
    }
}
