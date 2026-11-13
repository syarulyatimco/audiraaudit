<?php

namespace App\Ai\Runtime;

use App\Ai\Agents\AgentRegistry;
use App\Ai\Gateway\ModelRouter;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Engagement;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entry point for all AI work. Checks, in order: provider configured, firm opted in, agent not
 * disabled by the firm, the user holds the task's engagement permission, engagement editable,
 * no identical run in progress, credits remaining. Then queues the run.
 */
class AiOrchestrator
{
    public function __construct(
        private AgentRegistry $agents,
        private ModelRouter $router,
        private AiBudget $budget,
        private ActivityLogger $logger,
    ) {}

    /** Reasons AI cannot run for this firm right now (empty = available). @return list<string> */
    public function unavailableReasons(Engagement $engagement, ?string $agentKey = null): array
    {
        $firm = $engagement->firm;
        $reasons = [];
        if (! $this->router->isConfigured()) {
            $reasons[] = 'No AI provider is configured on this AUDIRA installation.';
        }
        if (! $firm->ai_enabled) {
            $reasons[] = 'AI assistance is turned off for your firm (Firm › AI settings).';
        }
        if ($agentKey && in_array($agentKey, $firm->ai_disabled_agents ?? [], true)) {
            $reasons[] = 'Your firm has disabled this agent.';
        }
        if ($firm->ai_enabled && $this->budget->remaining($firm) <= 0) {
            $reasons[] = 'Your firm has used its AI credits for this month.';
        }

        return $reasons;
    }

    public function start(Engagement $engagement, string $agentKey, string $task, User $user, array $input = []): AiRun
    {
        $agent = $this->agents->get($agentKey);
        $def = $agent->tasks()[$task] ?? throw ValidationException::withMessages(['task' => 'Unknown AI task.']);

        if ($reasons = $this->unavailableReasons($engagement, $agentKey)) {
            throw ValidationException::withMessages(['ai' => $reasons]);
        }
        EngagementGuard::assertEditable($engagement);
        if (! Permissions::allowsOnEngagement($user, $engagement, $def['permission'])) {
            throw new AuthorizationException('Your role on this engagement cannot run this AI task.');
        }

        $run = DB::transaction(function () use ($engagement, $agentKey, $task, $user, $input, $agent, $def) {
            $engagement->newQuery()->whereKey($engagement->id)->lockForUpdate()->first();
            if ($engagement->aiRuns()->where('agent', $agentKey)->where('task', $task)->whereIn('status', AiRun::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['ai' => 'This AI task is already running for the engagement.']);
            }
            $run = AiRun::create(['engagement_id' => $engagement->id, 'agent' => $agentKey, 'task' => $task, 'requested_by' => $user->id, 'input' => $input ?: null]);
            $this->logger->log('ai.run_requested', "{$agent->name()}: {$def['label']} requested", $engagement, ['run' => $run->uuid], $user);

            return $run;
        });

        RunAgentJob::dispatch($engagement->firm_id, $run->id)->afterCommit();

        return $run->fresh();
    }
}
