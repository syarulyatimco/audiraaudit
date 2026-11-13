<?php

namespace App\Ai\Tools;

/** Open monitoring alerts and progress counts of the engagement (summaries only). */
class EngagementHealthTool implements Tool
{
    public function name(): string
    {
        return 'engagement_health';
    }

    public function description(): string
    {
        return 'Open monitoring alerts (kind, severity, message) and progress of the engagement: status, reporting deadline, procedures by status, samples by status, open findings, client requests by status, working papers by status, completion and report approval states.';
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
        $e = $ctx->engagement;
        $count = fn ($q) => $q->get()->groupBy(fn ($m) => $m->status instanceof \BackedEnum ? $m->status->value : (string) $m->status)->map->count()->all();

        return [
            'status' => $e->status->value, 'reporting_deadline' => $e->reporting_deadline?->toDateString(), 'today' => now()->toDateString(),
            'alerts' => $e->alerts()->open()->get()->map(fn ($a) => ['kind' => $a->kind, 'severity' => $a->severity, 'message' => $a->message, 'since' => $a->first_seen_at->toDateString()])->all(),
            'procedures' => $count($e->procedures()->active()),
            'samples' => $count($e->samplingPlans()),
            'findings_open' => $e->findings()->where('status', 'open')->count(),
            'client_requests' => $count($e->pbcItems()),
            'working_papers' => $count($e->workingPapers()),
            'completion' => ['conclusions' => $e->completion?->conclusions_status?->value, 'completion' => $e->completion?->completion_status?->value],
            'report' => ['opinion' => $e->auditReport?->opinion_status?->value, 'signoff' => $e->auditReport?->signoff_status?->value],
        ];
    }
}
