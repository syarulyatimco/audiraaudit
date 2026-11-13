<?php

namespace App\Ai\Tools;

use App\Models\AccountMapping;

/** How the same client's accounts were mapped in its earlier engagements (same firm only). */
class MappingHistoryTool implements Tool
{
    public function name(): string
    {
        return 'mapping_history';
    }

    public function description(): string
    {
        return "Return how the given account codes were mapped (FSLI code) in this client's earlier engagements, most recent first.";
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['account_codes'], 'properties' => [
            'account_codes' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 50], 'maxItems' => 300],
        ], 'additionalProperties' => false];
    }

    public function requiredPermission(): string
    {
        return 'engagement.view';
    }

    public function handle(ToolContext $ctx, array $input): array
    {
        $e = $ctx->engagement;
        $rows = AccountMapping::query()
            ->join('engagements', 'engagements.id', '=', 'account_mappings.engagement_id')
            ->join('engagement_fslis', 'engagement_fslis.id', '=', 'account_mappings.engagement_fsli_id')
            ->where('engagements.client_id', $e->client_id)
            ->where('engagements.id', '!=', $e->id)
            ->whereIn('account_mappings.account_code', $input['account_codes'])
            ->orderByDesc('engagements.period_end')
            ->get(['account_mappings.account_code', 'engagement_fslis.code as fsli_code', 'engagements.period_end']);

        return ['history' => $rows->map(fn ($r) => ['account_code' => $r->account_code, 'fsli_code' => $r->fsli_code, 'period_end' => (string) $r->period_end])->all()];
    }
}
