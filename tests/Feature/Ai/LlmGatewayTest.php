<?php

namespace Tests\Feature\Ai;

use App\Ai\Gateway\AnthropicClient;
use App\Ai\Gateway\LlmRequest;
use App\Ai\Gateway\LlmRequestException;
use App\Ai\Gateway\LlmTransportException;
use App\Ai\Gateway\ModelRouter;
use App\Ai\Gateway\OpenAiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmGatewayTest extends TestCase
{
    private function request(): LlmRequest
    {
        return new LlmRequest('model-x', 'system rules', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'fsli_list', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{"fslis":[]}']]],
        ], [['name' => 'fsli_list', 'description' => 'd', 'input_schema' => ['type' => 'object']]], 500);
    }

    public function test_anthropic_request_and_response_mapping(): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'model' => 'model-x', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'content' => [['type' => 'text', 'text' => 'Checking'], ['type' => 'tool_use', 'id' => 't2', 'name' => 'tb_analytics', 'input' => ['a' => 1]]],
        ])]);
        $client = new AnthropicClient(array_merge(config('ai.providers.anthropic'), ['api_key' => 'k']));
        $res = $client->complete($this->request());

        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->hasHeader('x-api-key', 'k') && $r->hasHeader('anthropic-version', '2023-06-01')
                && $b['system'] === 'system rules' && $b['tools'][0]['name'] === 'fsli_list'
                && $b['messages'][2]['content'][0]['type'] === 'tool_result' && $b['max_tokens'] === 500;
        });
        $this->assertSame('Checking', $res->text);
        $this->assertSame([['id' => 't2', 'name' => 'tb_analytics', 'input' => ['a' => 1]]], $res->toolCalls);
        $this->assertSame([10, 5], [$res->inputTokens, $res->outputTokens]);
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('anthropic-workspace-id'));
    }

    public function test_anthropic_workspace_header_when_configured(): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response(['model' => 'm', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'content' => [['type' => 'text', 'text' => 'OK']]])]);
        (new AnthropicClient(array_merge(config('ai.providers.anthropic'), ['api_key' => 'k', 'workspace_id' => 'wrkspc_123'])))->complete($this->request());
        Http::assertSent(fn (Request $r) => $r->hasHeader('anthropic-workspace-id', 'wrkspc_123'));
    }

    public function test_openai_compatible_mapping(): void
    {
        Http::fake(['api.example.test/v1/chat/completions' => Http::response([
            'model' => 'gpt-x', 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
            'choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'fsli_list', 'arguments' => '{}']]]]]],
        ])]);
        $res = (new OpenAiClient(['api_key' => 'k', 'base_url' => 'https://api.example.test/v1']))->complete($this->request());

        Http::assertSent(function (Request $r) {
            $m = $r->data()['messages'];

            return $m[0] === ['role' => 'system', 'content' => 'system rules'] && $m[2]['tool_calls'][0]['function']['name'] === 'fsli_list'
                && $m[3] === ['role' => 'tool', 'tool_call_id' => 't1', 'content' => '{"fslis":[]}'] && $r->data()['tools'][0]['type'] === 'function';
        });
        $this->assertSame('fsli_list', $res->toolCalls[0]['name']);
        $this->assertSame(7, $res->inputTokens);
    }

    public function test_error_classification(): void
    {
        $client = new AnthropicClient(config('ai.providers.anthropic') + ['api_key' => 'k']);
        Http::fake(['*' => Http::sequence()->push(['error' => ['message' => 'overloaded']], 529)->push(['error' => ['message' => 'invalid x-api-key']], 401)]);

        try {
            $client->complete($this->request());
            $this->fail('expected transport exception');
        } catch (LlmTransportException $e) {
            $this->assertStringContainsString('529', $e->getMessage());
        }
        $this->expectException(LlmRequestException::class);
        $client->complete($this->request());
    }

    public function test_router_requires_configuration_and_maps_tiers(): void
    {
        config(['ai.providers.anthropic.api_key' => null]);
        $router = new ModelRouter;
        $this->assertFalse($router->isConfigured());
        config(['ai.providers.anthropic.api_key' => 'k']);
        [$client, $model] = $router->for('complex');
        $this->assertSame('anthropic', $client->provider());
        $this->assertSame(config('ai.models.anthropic.complex'), $model);
    }
}
