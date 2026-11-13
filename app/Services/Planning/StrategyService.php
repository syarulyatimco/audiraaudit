<?php

namespace App\Services\Planning;

use App\Models\AuditStrategy;
use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Overall audit strategy (ISA 300 topics), signed off through working paper A1. */
class StrategyService
{
    public function __construct(private PlanningPapers $papers, private ActivityLogger $logger) {}

    /** @param array{sections: array<string, ?string>, controls_approach: string, planning_meeting_on?: ?string, fieldwork_start_on?: ?string, fieldwork_end_on?: ?string, report_target_on?: ?string} $data */
    public function save(Engagement $engagement, array $data, User $actor): AuditStrategy
    {
        EngagementGuard::assertEditable($engagement);
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.edit_strategy')) {
            throw new AuthorizationException('Your role on this engagement does not allow editing the audit strategy.');
        }
        $keys = array_keys(config('audira.audit_strategy.sections'));
        $sections = [];
        foreach ($keys as $k) {
            $v = trim((string) ($data['sections'][$k] ?? ''));
            if ($v !== '') {
                $sections[$k] = $v;
            }
        }
        if (filled($data['fieldwork_start_on'] ?? null) && filled($data['fieldwork_end_on'] ?? null) && $data['fieldwork_end_on'] < $data['fieldwork_start_on']) {
            throw ValidationException::withMessages(['fieldwork_end_on' => 'Fieldwork cannot end before it starts.']);
        }

        return DB::transaction(function () use ($engagement, $data, $actor, $sections) {
            $this->papers->paper($engagement, WorkingPaper::AUDIT_STRATEGY, $actor);
            $strategy = $engagement->auditStrategy()->firstOrNew([]);
            $strategy->fill([
                'sections' => $sections,
                'controls_approach' => $data['controls_approach'],
                'planning_meeting_on' => $data['planning_meeting_on'] ?? null,
                'fieldwork_start_on' => $data['fieldwork_start_on'] ?? null,
                'fieldwork_end_on' => $data['fieldwork_end_on'] ?? null,
                'report_target_on' => $data['report_target_on'] ?? null,
                'updated_by' => $actor->id,
            ]);
            $dirty = array_keys($strategy->getDirty());
            $strategy->save();
            if ($dirty) {
                $this->logger->log('strategy.saved', 'Audit strategy updated', $strategy, ['fields' => $dirty], $actor);
                $this->papers->refresh($engagement, $actor, 'Audit strategy edited', [WorkingPaper::AUDIT_STRATEGY]);
            }

            return $strategy;
        });
    }
}
