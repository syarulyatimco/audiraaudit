<?php

namespace App\Ai\Tools;

use App\Services\Planning\MaterialityService;

class FsBenchmarksTool implements Tool
{
    public function __construct(private MaterialityService $materiality) {}

    public function name(): string
    {
        return 'fs_benchmarks';
    }

    public function description(): string
    {
        return 'Candidate materiality benchmarks computed by AUDIRA from the adjusted trial balance (current and prior year), in sen (RM x 100), plus data-quality issues. Benchmark amounts in your answer must be taken from here.';
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
        $b = $this->materiality->benchmarks($ctx->engagement);

        return [
            'currency' => 'MYR', 'unit' => 'sen',
            'current_year' => $b['current'],
            'prior_year' => $b['prior'] ?: null,
            'current_year_is_loss' => $b['loss'],
            'issues' => $b['issues'],
            'note' => 'Negative values mean a loss or a net liability position.',
        ];
    }
}
