<?php

namespace App\Contracts;

use App\Models\Approval;

/**
 * Any record that can be subject to a human approval gate (CLAUDE.md §9).
 */
interface Approvable
{
    public function approvalTitle(): string;

    public function approvalUrl(): string;

    /** The engagement this approval belongs to (null for firm-level subjects such as clients). */
    public function approvalEngagement(): ?\App\Models\Engagement;

    /** Apply the business effect of a decision. Called inside the decision transaction. */
    public function onApprovalDecided(Approval $approval): void;
}
