<?php

namespace App\Ai\Tools;

class EngagementOverviewTool implements Tool
{
    public function name(): string
    {
        return 'engagement_overview';
    }

    public function description(): string
    {
        return 'Basic engagement facts for client correspondence: client name, period, reporting deadline, planned fieldwork dates, the audit firm name and the engagement manager\'s name.';
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
        $e = $ctx->engagement->loadMissing(['client', 'members.user']);
        $s = $e->auditStrategy()->first();

        return [
            'firm' => $e->firm()->value('name'), 'client' => $e->client->name, 'period_end' => $e->period_end->toDateString(),
            'reporting_deadline' => $e->reporting_deadline?->toDateString(),
            'fieldwork' => ['start' => $s?->fieldwork_start_on?->toDateString(), 'end' => $s?->fieldwork_end_on?->toDateString()],
            'manager' => $e->members->firstWhere('role.value', 'manager')?->user?->name,
            'sender' => $ctx->requester->name,
        ];
    }
}
