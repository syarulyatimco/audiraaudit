<?php

namespace App\Ai\Gateway;

use Illuminate\Support\Facades\Http;

/** Anthropic Messages API driver. */
class AnthropicClient implements LlmClient
{
    use HttpErrors;

    public function __construct(private array $config) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => array_map(fn ($m) => [
                'role' => $m['role'],
                'content' => array_map(fn ($b) => match ($b['type']) {
                    'tool_use' => ['type' => 'tool_use', 'id' => $b['id'], 'name' => $b['name'], 'input' => (object) $b['input']],
                    default => $b,
                }, $m['content']),
            ], $request->messages),
        ];
        if ($request->tools) {
            $body['tools'] = $request->tools;
        }

        $headers = [
            'x-api-key' => (string) $this->config['api_key'],
            'anthropic-version' => $this->config['version'] ?? '2023-06-01',
        ];
        // Keys that are not scoped to a workspace must name one on every request.
        if (filled($this->config['workspace_id'] ?? null)) {
            $headers['anthropic-workspace-id'] = (string) $this->config['workspace_id'];
        }
        $http = Http::withHeaders($headers)->timeout($this->config['timeout'] ?? 120)->acceptJson();

        $json = $this->send($http, rtrim($this->config['base_url'], '/').'/v1/messages', $body);

        $text = '';
        $calls = [];
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $calls[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => (array) ($block['input'] ?? [])];
            }
        }

        return new LlmResponse($text, $calls, (string) ($json['stop_reason'] ?? ''),
            (int) ($json['usage']['input_tokens'] ?? 0), (int) ($json['usage']['output_tokens'] ?? 0), (string) ($json['model'] ?? $request->model));
    }
}
