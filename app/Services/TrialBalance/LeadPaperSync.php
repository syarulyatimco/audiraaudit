<?php

namespace App\Services\TrialBalance;

use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Services\WorkingPapers\WorkingPaperService;

/**
 * Lead-schedule working papers attest to their figures (ADR-009). When the TB, mappings or posted
 * adjustments change, papers whose figures changed lose their sign-offs.
 */
class LeadPaperSync
{
    public function __construct(private WorkingPaperService $papers) {}

    public function refresh(Engagement $engagement, User $actor, string $reason): void
    {
        $ids = $engagement->fslis()->whereNotNull('working_paper_id')->pluck('working_paper_id');
        foreach (WorkingPaper::whereIn('id', $ids)->get() as $wp) {
            $this->papers->contentChanged($wp, $actor, $reason);
        }
    }
}
