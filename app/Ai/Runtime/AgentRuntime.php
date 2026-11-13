<?php

namespace App\Ai\Runtime;

use App\Ai\Agents\AgentRegistry;
use App\Ai\Gateway\LlmClient;
use App\Ai\Gateway\LlmRequest;
use App\Ai\Gateway\LlmRequestException;
use App\Ai\Gateway\LlmResponse;
use App\Ai\Gateway\LlmTransportException;
use App\Ai\Gateway\ModelRouter;
use App\Ai\Guardrails\JsonSchema;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Models\AiRun;
use App\Models\AiUsage;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use Throwable;

/**
 * Executes one agent run: LLM ↔ tool loop with allow-listed, permission-checked tools; retries
 * transient provider errors; one repair attempt for malformed output; guardrails; metering;
 * append-only step log. Never writes audit data — the output is a draft for people to act on.
 */
class AgentRuntime
{
    private int $seq = 0;

    public function __construct(
        private AgentRegistry $agents,
        private ToolRegistry $tools,
        private ModelRouter $router,
        private AiBudget $budget,
        private ActivityLogger $logger,
    ) {}

    public function execute(AiRun $run): void
    {
        $agent = $this->agents->get($run->agent);
        $def = $agent->tasks()[$run->task];
        $engagement = $run->engagement()->with(['client', 'firm'])->firstOrFail();
        $requester = $run->requester;
        $this->seq = (int) $run->steps()->max('seq');

        $run->forceFill(['status' => 'running', 'started_at' => now(), 'attempts' => $run->attempts + 1])->save();

        try {
            [$client, $model] = $this->router->for($def['tier']);
            $run->forceFill(['provider' => $client->provider(), 'model' => $model])->save();

            // Tools = agent allow-list ∩ what the requesting user is permitted to see.
            /** @var array<string, Tool> $allowed */
            $allowed = [];
            foreach ($this->tools->many($agent->tools()) as $tool) {
                if (Permissions::allowsOnEngagement($requester, $engagement, $tool->requiredPermission())) {
                    $allowed[$tool->name()] = $tool;
                }
            }
            $toolDefs = array_values(array_map(fn (Tool $t) => ['name' => $t->name(), 'description' => $t->description(), 'input_schema' => $t->inputSchema()], $allowed));
            $ctx = new ToolContext($run, $engagement, $requester);

            $system = $agent->systemPrompt($run->task, $engagement)."\n\nRequired output JSON schema:\n".json_encode($agent->outputSchema($run->task));
            $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => $agent->instruction($run->task, $engagement, $run->input ?? [])]]]];
            $toolResults = [];
            $repaired = false;

            for ($i = 0; $i < (int) config('ai.max_steps'); $i++) {
                if ($this->budget->remaining($engagement->firm) <= 0) {
                    $this->finish($run, 'failed', null, 'The firm has used its AI credits for this month.', $agent->name());

                    return;
                }

                $response = $this->call($run, $client, new LlmRequest($model, $system, $messages, $toolDefs, (int) config('ai.max_output_tokens')));
                $messages[] = $response->assistantMessage();

                if ($response->wantsTools()) {
                    $blocks = [];
                    foreach ($response->toolCalls as $call) {
                        $result = $this->runTool($call, $allowed, $ctx);
                        if (! isset($result['error'])) {
                            $toolResults[] = $result;
                        }
                        $encoded = mb_substr(json_encode($result, JSON_UNESCAPED_UNICODE), 0, (int) config('ai.max_tool_result_chars'));
                        $blocks[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $encoded];
                    }
                    $messages[] = ['role' => 'user', 'content' => $blocks];

                    continue;
                }

                $json = JsonSchema::extractJson($response->text);
                $errors = $json === null ? ['The answer was not a JSON object.'] : JsonSchema::validate($json, $agent->outputSchema($run->task));
                if ($errors) {
                    $this->step($run, 'guardrail', 'schema', ['errors' => $errors]);
                    if (! $repaired) {
                        $repaired = true;
                        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => "Your answer did not match the required schema:\n- ".implode("\n- ", array_slice($errors, 0, 10))."\nReturn only the corrected JSON object."]]];

                        continue;
                    }
                    $this->finish($run, 'needs_review', null, 'The AI output did not match the required structure after one correction attempt.', $agent->name());

                    return;
                }

                $guarded = $agent->guard($run->task, $run, $json, $toolResults);
                $this->step($run, 'guardrail', 'business_rules', ['violations' => $guarded['violations']]);
                $output = $guarded['output'] + ['violations' => $guarded['violations']];
                $this->finish($run, $guarded['violations'] ? 'needs_review' : 'completed', $output, null, $agent->name());

                return;
            }

            $this->finish($run, 'failed', null, 'The agent did not finish within '.config('ai.max_steps').' steps.', $agent->name());
        } catch (LlmRequestException|LlmTransportException $e) {
            $this->step($run, 'error', class_basename($e), ['message' => $e->getMessage()]);
            $this->finish($run, 'failed', null, $e->getMessage(), $agent->name());
        } catch (Throwable $e) {
            report($e);
            $this->step($run, 'error', class_basename($e), ['message' => mb_substr($e->getMessage(), 0, 500)]);
            $this->finish($run, 'failed', null, 'Unexpected error while running the agent.', $agent->name());
        }
    }

    private function call(AiRun $run, LlmClient $client, LlmRequest $request): LlmResponse
    {
        $backoff = (array) config('ai.retry_backoff_seconds', [2, 5]);
        $last = $request->messages[array_key_last($request->messages)];
        $this->step($run, 'llm_request', $request->model, ['messages' => count($request->messages), 'last' => $this->truncate($last)]);

        for ($attempt = 0; ; $attempt++) {
            $t = microtime(true);
            try {
                $response = $client->complete($request);
                break;
            } catch (LlmTransportException $e) {
                if ($attempt >= count($backoff)) {
                    throw $e;
                }
                $this->step($run, 'retry', $client->provider(), ['attempt' => $attempt + 1, 'message' => $e->getMessage()]);
                sleep((int) $backoff[$attempt]);
            }
        }

        $ms = (int) ((microtime(true) - $t) * 1000);
        $credits = AiBudget::creditsFor($response->inputTokens + $response->outputTokens);
        $this->step($run, 'llm_response', $response->model, [
            'stop_reason' => $response->stopReason, 'text' => mb_substr($response->text, 0, 20000), 'tool_calls' => $response->toolCalls,
        ], $response->inputTokens, $response->outputTokens, $ms);

        AiUsage::create([
            'firm_id' => $run->firm_id, 'engagement_id' => $run->engagement_id, 'ai_run_id' => $run->id,
            'provider' => $client->provider(), 'model' => $response->model,
            'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens,
            'cost_micros' => $this->router->costMicros($response->model, $response->inputTokens, $response->outputTokens),
            'credits' => $credits,
        ]);
        $run->forceFill([
            'input_tokens' => $run->input_tokens + $response->inputTokens,
            'output_tokens' => $run->output_tokens + $response->outputTokens,
            'credits' => $run->credits + $credits,
        ])->save();

        return $response;
    }

    /** @param array<string, Tool> $allowed */
    private function runTool(array $call, array $allowed, ToolContext $ctx): array
    {
        $name = $call['name'];
        if (! isset($allowed[$name])) {
            $this->step($ctx->run, 'tool_denied', mb_substr($name, 0, 80), ['input' => $call['input']]);

            return ['error' => "Tool {$name} is not available to this agent."];
        }
        $tool = $allowed[$name];
        if ($errors = JsonSchema::validate($call['input'], $tool->inputSchema())) {
            $this->step($ctx->run, 'tool_denied', $name, ['input' => $call['input'], 'errors' => $errors]);

            return ['error' => 'Invalid input: '.implode('; ', $errors)];
        }

        $this->step($ctx->run, 'tool_call', $name, ['input' => $call['input']]);
        $t = microtime(true);
        $result = $tool->handle($ctx, $call['input']);
        $this->step($ctx->run, 'tool_result', $name, ['result' => $this->truncate($result)], 0, 0, (int) ((microtime(true) - $t) * 1000));

        return $result;
    }

    private function finish(AiRun $run, string $status, ?array $output, ?string $error, string $agentName): void
    {
        $run->forceFill(['status' => $status, 'output' => $output, 'error' => $error, 'finished_at' => now()])->save();
        $verb = ['completed' => 'completed', 'needs_review' => 'completed with items to review', 'failed' => 'failed'][$status] ?? $status;
        $this->logger->log('ai.run_'.$status, "{$agentName}: {$run->task} {$verb}", $run->engagement, [
            'run' => $run->uuid, 'credits' => $run->credits, 'error' => $error,
        ], null, $run->firm_id, 'agent', $agentName);
    }

    private function step(AiRun $run, string $type, ?string $name, array $payload, int $in = 0, int $out = 0, int $ms = 0): void
    {
        $run->steps()->create([
            'seq' => ++$this->seq, 'type' => $type, 'name' => $name, 'payload' => $payload,
            'input_tokens' => $in, 'output_tokens' => $out, 'duration_ms' => $ms,
        ]);
    }

    private function truncate(mixed $data): mixed
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return strlen($json) > 20000 ? ['truncated' => true, 'preview' => mb_substr($json, 0, 20000)] : $data;
    }
}
