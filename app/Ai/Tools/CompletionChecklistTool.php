<?php

namespace App\Ai\Tools;

use App\Enums\FindingStatus;
use App\Services\Findings\AuditDifferences;

/** Open completion checklist items plus a summary of the engagement record relevant to completion. */
class CompletionChecklistTool implements Tool
{
    public function __construct(private AuditDifferences $sad) {}

    public function name(): string
    {
        return 'completion_checklist';
    }

    public function description(): string
    {
        return 'Open completion checklist items (key, section, title, guidance) and a summary of the engagement record: summary of audit differences (amounts in sen), findings by type and status, procedures by status, open client requests, period end.';
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
        $sad = $this->sad->build($e);
        $findings = $e->findings()->active()->get();

        return [
            'period_end' => $e->period_end?->toDateString(),
            'open_items' => $e->completionItems()->where('status', 'open')->get()->map(fn ($i) => ['key' => $i->key, 'section' => $i->section, 'title' => $i->title, 'guidance' => $i->guidance()])->all(),
            'audit_differences' => [
                'overall_materiality_sen' => $sad['om_sen'], 'performance_materiality_sen' => $sad['pm_sen'], 'clearly_trivial_sen' => $sad['ct_sen'],
                'uncorrected_net_sen' => $sad['net_sen'], 'uncorrected_gross_sen' => $sad['gross_sen'], 'uncorrected_count' => $sad['uncorrected']->count(),
                'corrected_count' => $sad['corrected']->count(), 'awaiting_management' => $sad['flags']['awaiting_management'],
                'uncorrected' => $sad['uncorrected']->map(fn ($f) => ['reference' => $f->reference, 'title' => $f->title, 'class' => $f->misstatement_class->value, 'amount_sen' => $f->amount_sen, 'reason' => $f->uncorrected_reason])->all(),
            ],
            'findings' => [
                'control_deficiencies' => $findings->where('type.value', 'control_deficiency')->map(fn ($f) => ['reference' => $f->reference, 'title' => $f->title, 'severity' => $f->deficiency_severity?->value, 'status' => $f->status->value])->values()->all(),
                'other_matters' => $findings->where('type.value', 'other')->map(fn ($f) => ['reference' => $f->reference, 'title' => $f->title, 'status' => $f->status->value])->values()->all(),
                'open' => $findings->where('status', FindingStatus::Open)->count(),
            ],
            'procedures' => $e->procedures()->active()->get()->groupBy(fn ($p) => $p->status->value)->map->count()->all(),
            'open_client_requests' => $e->pbcItems()->whereIn('status', ['requested', 'returned', 'submitted'])->count(),
        ];
    }
}
