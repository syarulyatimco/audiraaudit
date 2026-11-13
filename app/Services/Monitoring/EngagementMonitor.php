<?php

namespace App\Services\Monitoring;

use App\Enums\EngagementRole;
use App\Enums\EngagementStatus;
use App\Enums\ReviewNoteStatus;
use App\Models\Approval;
use App\Models\Engagement;
use App\Models\Firm;
use App\Models\MonitoringAlert;
use App\Models\ReviewNote;
use App\Notifications\MonitoringAlertsNotification;
use App\Services\Findings\AuditDifferences;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Engagement Monitoring (docs/AI_AGENTS.md #12, rules part): daily, per firm, evaluates
 * deterministic health rules for every open engagement, keeps one open alert per rule and
 * notifies the engagement partner and manager of NEW alerts. It reads summaries only and never
 * changes audit records.
 */
class EngagementMonitor
{
    public function __construct(private TenantContext $tenant, private AuditDifferences $sad) {}

    /** @return array{engagements: int, opened: int, resolved: int} */
    public function runAll(?Carbon $now = null): array
    {
        $totals = ['engagements' => 0, 'opened' => 0, 'resolved' => 0];
        $firmIds = $this->tenant->runAsSystem(fn () => Firm::whereIn('status', ['trial', 'active'])->pluck('id'));
        foreach ($firmIds as $firmId) {
            $this->tenant->runForFirm($firmId, function () use (&$totals, $now) {
                foreach (Engagement::whereNotIn('status', [EngagementStatus::SignedOff->value, EngagementStatus::Archived->value])->get() as $e) {
                    $r = $this->scan($e, $now);
                    $totals['engagements']++;
                    $totals['opened'] += $r['opened'];
                    $totals['resolved'] += $r['resolved'];
                }
            });
        }

        return $totals;
    }

    /** @return array{opened: int, resolved: int} */
    public function scan(Engagement $e, ?Carbon $now = null): array
    {
        $now ??= now();
        $found = $this->evaluate($e, $now);
        $open = $e->alerts()->open()->get()->keyBy('kind');
        $opened = 0;
        $new = [];
        foreach ($found as $kind => [$severity, $message, $data]) {
            if ($a = $open->get($kind)) {
                $a->forceFill(['severity' => $severity, 'message' => $message, 'data' => $data, 'last_seen_at' => $now])->save();
            } else {
                $new[] = MonitoringAlert::create(['engagement_id' => $e->id, 'kind' => $kind, 'severity' => $severity, 'message' => $message, 'data' => $data, 'first_seen_at' => $now, 'last_seen_at' => $now]);
                $opened++;
            }
        }
        $resolved = 0;
        foreach ($open->reject(fn ($a, $kind) => isset($found[$kind])) as $a) {
            $a->forceFill(['resolved_at' => $now])->save();
            $resolved++;
        }
        if ($new) {
            $recipients = $e->members()->whereIn('role', [EngagementRole::EngagementPartner->value, EngagementRole::Manager->value])->with('user')->get()->pluck('user')->filter(fn ($u) => $u->status->value === 'active');
            foreach ($recipients as $u) {
                $u->notify(new MonitoringAlertsNotification($e, collect($new)));
            }
            MonitoringAlert::whereIn('id', collect($new)->pluck('id'))->update(['notified_at' => $now]);
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> kind => [severity, message, data] */
    public function evaluate(Engagement $e, Carbon $now): array
    {
        $cfg = config('audira.monitoring');
        $out = [];

        if ($e->reporting_deadline) {
            $days = (int) $now->copy()->startOfDay()->diffInDays($e->reporting_deadline->copy()->startOfDay(), false);
            if ($days < 0) {
                $out['deadline'] = ['danger', 'Reporting deadline passed '.abs($days).' day(s) ago ('.$e->reporting_deadline->format('j M Y').').', ['days' => $days]];
            } elseif ($days <= $cfg['deadline_warning_days']) {
                $out['deadline'] = ['warn', "Reporting deadline in {$days} day(s) (".$e->reporting_deadline->format('j M Y').').', ['days' => $days]];
            }
        }

        $overdue = $e->pbcItems()->whereIn('status', ['requested', 'returned'])->whereNotNull('due_on')->whereDate('due_on', '<', $now->toDateString())->count();
        if ($overdue) {
            $out['overdue_requests'] = [$overdue >= 5 ? 'danger' : 'warn', "{$overdue} client request(s) overdue.", ['count' => $overdue]];
        }

        $stale = Approval::where('engagement_id', $e->id)->pending()->where('requested_at', '<', $now->copy()->subDays($cfg['stale_approval_days']))->count();
        if ($stale) {
            $out['stale_approvals'] = ['warn', "{$stale} approval request(s) pending for more than {$cfg['stale_approval_days']} days.", ['count' => $stale]];
        }

        $notes = ReviewNote::whereIn('working_paper_id', $e->workingPapers()->pluck('id'))->where('status', '!=', ReviewNoteStatus::Cleared->value)
            ->where('created_at', '<', $now->copy()->subDays($cfg['stale_review_note_days']))->count();
        if ($notes) {
            $out['stale_review_notes'] = ['warn', "{$notes} review note(s) open for more than {$cfg['stale_review_note_days']} days.", ['count' => $notes]];
        }

        $sad = $this->sad->build($e);
        if ($sad['flags']['aggregate_at_or_above_om'] || $sad['flags']['individual_at_or_above_om']) {
            $out['misstatements'] = ['danger', 'Uncorrected misstatements are at or above overall materiality.', ['net_sen' => $sad['net_sen'], 'gross_sen' => $sad['gross_sen']]];
        } elseif ($sad['flags']['aggregate_at_or_above_pm']) {
            $out['misstatements'] = ['warn', 'Uncorrected misstatements are at or above performance materiality.', ['net_sen' => $sad['net_sen'], 'gross_sen' => $sad['gross_sen']]];
        }

        if (in_array($e->status, [EngagementStatus::Fieldwork, EngagementStatus::Completion, EngagementStatus::Reporting], true) && ! $e->currentMateriality()) {
            $out['no_materiality'] = ['warn', 'Fieldwork has started without approved materiality.', []];
        }

        return $out;
    }
}
