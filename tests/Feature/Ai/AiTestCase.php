<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Http;
use Tests\Feature\TrialBalance\TrialBalanceTestCase;

abstract class AiTestCase extends TrialBalanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.default_provider' => 'anthropic',
            'ai.providers.anthropic.api_key' => 'test-key',
            'ai.retry_backoff_seconds' => [0, 0],
        ]);
        Http::preventStrayRequests();
        $firm = $this->ctx['firm'];
        $firm->forceFill(['ai_enabled' => true, 'ai_enabled_at' => now()])->save();
        $firm->currentSubscription()->first()->plan->update(['ai_credits_per_user' => 100]);
    }

    protected function toolUse(array $calls, int $in = 1200, int $out = 80): array
    {
        return ['id' => 'msg_'.uniqid(), 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
            'content' => array_map(fn ($c) => ['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $c[0], 'input' => (object) ($c[1] ?? [])], $calls)];
    }

    protected function final(string|array $text, int $in = 2500, int $out = 400): array
    {
        return ['id' => 'msg_'.uniqid(), 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
            'content' => [['type' => 'text', 'text' => is_array($text) ? json_encode($text) : $text]]];
    }

    protected function startRun(string $task, $user = null, string $agent = 'accounting_tb')
    {
        $this->actingAs($user ?? $this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => $agent, 'task' => $task])->assertSessionHasNoErrors();

        return $this->ctx['engagement']->aiRuns()->first();
    }
}
