<?php

namespace App\Ai\Tools;

use App\Services\TrialBalance\LeadScheduleService;

class TbListAccountsTool implements Tool
{
    public function __construct(private LeadScheduleService $schedules) {}

    public function name(): string
    {
        return 'tb_list_accounts';
    }

    public function description(): string
    {
        return 'List trial balance accounts of this engagement with current-year and prior-year balances (RM, debit positive) and current FSLI mapping. Use filter "unmapped" to list only accounts without a mapping.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'filter' => ['type' => 'string', 'enum' => ['all', 'unmapped']],
            'offset' => ['type' => 'integer', 'minimum' => 0],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300],
        ], 'additionalProperties' => false];
    }

    public function requiredPermission(): string
    {
        return 'engagement.view';
    }

    public function handle(ToolContext $ctx, array $input): array
    {
        $accounts = $this->schedules->accounts($ctx->engagement);
        $fslis = $ctx->engagement->fslis()->pluck('code', 'id');
        if (($input['filter'] ?? 'all') === 'unmapped') {
            $accounts = $accounts->whereNull('fsli_id');
        }
        $total = $accounts->count();
        $slice = $accounts->values()->slice((int) ($input['offset'] ?? 0), (int) ($input['limit'] ?? 300));

        return [
            'total' => $total,
            'accounts' => $slice->map(fn ($a) => [
                'code' => $a['code'],
                'name' => $a['name'],
                'cy' => round($a['cy'] / 100, 2),
                'py' => round($a['py'] / 100, 2),
                'fsli' => $a['fsli_id'] ? $fslis[$a['fsli_id']] ?? null : null,
            ])->values()->all(),
        ];
    }
}
