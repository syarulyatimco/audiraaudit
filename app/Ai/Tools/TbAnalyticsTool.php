<?php

namespace App\Ai\Tools;

use App\Services\TrialBalance\LeadScheduleService;

/**
 * Deterministic analytics computed by AUDIRA (not the model): per-FSLI balances, changes and
 * opposite-side accounts, plus statement totals and integrity checks. Amounts in RM.
 */
class TbAnalyticsTool implements Tool
{
    public function __construct(private LeadScheduleService $schedules) {}

    public function name(): string
    {
        return 'tb_analytics';
    }

    public function description(): string
    {
        return 'Return computed lead-schedule analytics for this engagement: per FSLI prior year, adjusted current year, change and change %, accounts with balances opposite to the FSLI, statement totals and integrity checks. Use these figures exactly; do not compute new amounts.';
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
        $d = $this->schedules->build($ctx->engagement);
        $rm = fn (int $sen) => round($sen / 100, 2);

        return [
            'fslis' => $d['fslis']->filter(fn ($s) => $s['accounts']->isNotEmpty())->map(fn ($s) => [
                'fsli_code' => $s['fsli']->code,
                'name' => $s['fsli']->name,
                'classification' => $s['fsli']->classification->value,
                'prior_year' => $rm($s['py']),
                'current_year_adjusted' => $rm($s['cy_adj']),
                'change' => $rm($s['cy_adj'] - $s['py']),
                'change_pct' => $s['py'] !== 0 ? round(($s['cy_adj'] - $s['py']) / abs($s['py']) * 100, 1) : null,
                'opposite_balance_accounts' => $s['contra'],
            ])->values()->all(),
            'totals' => [
                'current_year' => array_map($rm, $d['cy']),
                'prior_year' => array_map($rm, $d['py']),
            ],
            'checks' => ['tb_balances' => $d['checks']['tb_balances'], 'unmapped_accounts' => $d['checks']['unmapped_count']],
        ];
    }
}
