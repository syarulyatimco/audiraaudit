<?php

namespace App\Ai\Gateway;

use Illuminate\Support\Facades\Http;

/** OpenAI-compatible Chat Completions driver (OpenAI, Azure OpenAI, self-hosted gateways). */
class OpenAiClient implements LlmClient
{
    use HttpErrors;

    public function __construct(private array $config) {}

    public function provider(): string
    {
        return 'openai';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $messages = [['role' => 'system', 'content' => $request->system]];
        foreach ($request->messages as $m) {
            $text = implode("\n", array_column(array_filter($m['content'], fn ($b) => $b['type'] === 'text'), 'text'));
            $uses = array_values(array_filter($m['content'], fn ($b) => $b['type'] === 'tool_use'));
            $results = array_values(array_filter($m['content'], fn ($b) => $b['type'] === 'tool_result'));

            if ($m['role'] === 'assistant') {
                $msg = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
                if ($uses) {
                    $msg['tool_calls'] = array_map(fn ($u) => ['id' => $u['id'], 'type' => 'function',
                        'function' => ['name' => $u['name'], 'arguments' => json_encode((object) $u['input'])]], $uses);
                }
                $messages[] = $msg;
            } else {
                foreach ($results as $r) {
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $r['tool_use_id'], 'content' => $r['content']];
                }
                if ($text !== '') {
                    $messages[] = ['role' => 'user', 'content' => $text];
                }
            }
        }

        $body = ['model' => $request->model, 'max_tokens' => $request->maxTokens, 'messages' => $messages];
        if ($request->tools) {
            $body['tools'] = array_map(fn ($t) => ['type' => 'function', 'function' => [
                'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema']]], $request->tools);
        }

        $http = Http::withToken((string) $this->config['api_key'])->timeout($this->config['timeout'] ?? 120)->acceptJson();
        $json = $this->send($http, rtrim($this->config['base_url'], '/').'/chat/completions', $body);

        $choice = $json['choices'][0] ?? [];
        $calls = [];
        foreach ($choice['message']['tool_calls'] ?? [] as $c) {
            $args = json_decode($c['function']['arguments'] ?? '{}', true);
            $calls[] = ['id' => $c['id'], 'name' => $c['function']['name'], 'input' => is_array($args) ? $args : []];
        }

        return new LlmResponse((string) ($choice['message']['content'] ?? ''), $calls, (string) ($choice['finish_reason'] ?? ''),
            (int) ($json['usage']['prompt_tokens'] ?? 0), (int) ($json['usage']['completion_tokens'] ?? 0), (string) ($json['model'] ?? $request->model));
    }
}
