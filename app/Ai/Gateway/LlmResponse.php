<?php

namespace App\Ai\Gateway;

final class LlmResponse
{
    /**
     * @param  list<array{id: string, name: string, input: array}>  $toolCalls
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly string $stopReason,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $model,
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /** The assistant turn in canonical block format, to append to the conversation. */
    public function assistantMessage(): array
    {
        $content = [];
        if ($this->text !== '') {
            $content[] = ['type' => 'text', 'text' => $this->text];
        }
        foreach ($this->toolCalls as $c) {
            $content[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => $c['input']];
        }

        return ['role' => 'assistant', 'content' => $content];
    }
}
