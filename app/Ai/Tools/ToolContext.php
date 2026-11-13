<?php

namespace App\Ai\Tools;

use App\Models\AiRun;
use App\Models\Engagement;
use App\Models\User;

/** The only scope a tool can see: one firm, one engagement, on behalf of one user. */
final class ToolContext
{
    public function __construct(
        public readonly AiRun $run,
        public readonly Engagement $engagement,
        public readonly User $requester,
    ) {}
}
