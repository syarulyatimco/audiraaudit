<?php

namespace App\Jobs;

use App\Ai\Runtime\AgentRuntime;
use App\Models\AiRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Runs an agent on the `ai` queue inside its firm's tenant context (RLS restored). */
class RunAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;      // provider retries happen inside the runtime with backoff

    public int $timeout = 900;

    public function __construct(public int $firmId, public int $runId)
    {
        $this->onQueue('ai');
    }

    public function handle(TenantContext $tenant, AgentRuntime $runtime): void
    {
        $tenant->runForFirm($this->firmId, function () use ($runtime) {
            $run = AiRun::find($this->runId);
            if ($run && $run->status === 'queued') {
                $runtime->execute($run);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->runForFirm($this->firmId, function () use ($e) {
            AiRun::whereKey($this->runId)->whereIn('status', AiRun::ACTIVE)->update([
                'status' => 'failed', 'error' => 'Worker failure: '.mb_substr((string) $e?->getMessage(), 0, 300), 'finished_at' => now(),
            ]);
        });
    }
}
