<?php

namespace App\Services\Findings;

use App\Enums\FindingType;
use App\Models\Engagement;
use App\Models\Finding;

/**
 * Summary of audit differences (ISA 450). Accumulates misstatements identified during the audit
 * other than those that are clearly trivial (by the approved clearly-trivial threshold), and
 * compares uncorrected misstatements — individually, by line item and in aggregate — with the
 * approved overall, performance and specific materiality. The comparison is information for the
 * engagement team's evaluation; whether uncorrected misstatements are material, and the effect on
 * the opinion, is the engagement partner's judgement.
 */
class AuditDifferences
{
    /** @return array<string, mixed> */
    public function build(Engagement $engagement): array
    {
        $m = $engagement->currentMateriality();
        $om = $m ? (int) $m->om_sen : null;
        $pm = $m ? (int) $m->pm_sen : null;
        $ct = $m ? (int) $m->ct_sen : null;
        $specific = $m ? $m->specifics()->whereNotNull('engagement_fsli_id')->pluck('amount_sen', 'engagement_fsli_id')->map(fn ($v) => (int) $v)->all() : [];

        $all = Finding::where('engagement_id', $engagement->id)->where('type', FindingType::Misstatement->value)->active()
            ->with('fsli', 'adjustment')->orderBy('reference')->get();

        $trivial = $ct !== null ? $all->filter(fn ($f) => abs($f->amount_sen) < $ct) : collect();
        $accumulated = $all->diff($trivial);
        $uncorrected = $accumulated->filter->isUncorrected();
        $corrected = $accumulated->reject->isUncorrected();

        $byFsli = $uncorrected->groupBy('engagement_fsli_id')->map(function ($group, $fsliId) use ($specific, $om) {
            $net = (int) $group->sum('amount_sen');
            $limit = $specific[$fsliId] ?? $om;

            return [
                'fsli' => $group->first()->fsli,
                'factual_sen' => (int) $group->where('misstatement_class.value', 'factual')->sum('amount_sen'),
                'judgemental_sen' => (int) $group->where('misstatement_class.value', 'judgemental')->sum('amount_sen'),
                'projected_sen' => (int) $group->where('misstatement_class.value', 'projected')->sum('amount_sen'),
                'net_sen' => $net,
                'limit_sen' => $limit,
                'limit_is_specific' => isset($specific[$fsliId]),
                'at_or_above_limit' => $limit !== null && abs($net) >= $limit,
            ];
        })->sortBy(fn ($r) => $r['fsli']?->sort_order)->values();

        $net = (int) $uncorrected->sum('amount_sen');
        $gross = (int) $uncorrected->sum(fn ($f) => abs($f->amount_sen));

        return [
            'materiality' => $m, 'om_sen' => $om, 'pm_sen' => $pm, 'ct_sen' => $ct,
            'uncorrected' => $uncorrected->values(), 'corrected' => $corrected->values(), 'trivial' => $trivial->values(),
            'trivial_total_sen' => (int) $trivial->sum(fn ($f) => abs($f->amount_sen)),
            'by_fsli' => $byFsli,
            'net_sen' => $net,
            'gross_sen' => $gross,
            'by_class' => collect(['factual', 'judgemental', 'projected'])->mapWithKeys(fn ($c) => [$c => (int) $uncorrected->where('misstatement_class.value', $c)->sum('amount_sen')])->all(),
            'flags' => [
                'no_materiality' => $m === null,
                'aggregate_at_or_above_om' => $om !== null && max(abs($net), $gross) >= $om,
                'aggregate_at_or_above_pm' => $pm !== null && max(abs($net), $gross) >= $pm,
                'individual_at_or_above_om' => $om !== null && $uncorrected->contains(fn ($f) => abs($f->amount_sen) >= $om),
                'fsli_at_or_above_limit' => $byFsli->contains('at_or_above_limit', true),
                'missing_reasons' => $uncorrected->filter(fn ($f) => $f->correction_status?->value === 'uncorrected' && blank($f->uncorrected_reason))->count(),
                'awaiting_management' => $uncorrected->filter(fn ($f) => $f->correction_status?->value === 'proposed')->count(),
            ],
        ];
    }
}
