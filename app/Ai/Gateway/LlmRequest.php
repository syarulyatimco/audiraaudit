<?php

namespace App\Ai\Gateway;

/**
 * Provider-neutral request. Messages use a canonical block format:
 *  ['role' => 'user'|'assistant', 'content' => [
 *      ['type' => 'text', 'text' => '...'],
 *      ['type' => 'tool_use', 'id' => '...', 'name' => '...', 'input' => [...]],      // assistant
 *      ['type' => 'tool_result', 'tool_use_id' => '...', 'content' => '...json...'],   // user
 *  ]]
 */
final class LlmRequest
{
    /**
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array}>  $tools
     */
    public function __construct(
        public readonly string $model,
        public readonly string $system,
        public readonly array $messages,
        public readonly array $tools = [],
        public readonly int $maxTokens = 4096,
    ) {}
}
