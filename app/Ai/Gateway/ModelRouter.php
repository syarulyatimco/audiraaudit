<?php

namespace App\Ai\Gateway;

/**
 * Chooses provider and model by task complexity tier ("fast" | "standard" | "complex").
 */
class ModelRouter
{
    public function isConfigured(?string $provider = null): bool
    {
        $provider ??= config('ai.default_provider');

        return filled(config("ai.providers.{$provider}.api_key")) && filled(config("ai.models.{$provider}.standard"));
    }

    /** @return array{0: LlmClient, 1: string} client and model */
    public function for(string $tier): array
    {
        $provider = config('ai.default_provider');
        if (! $this->isConfigured($provider)) {
            throw new LlmRequestException("AI provider \"{$provider}\" is not configured.");
        }
        $model = config("ai.models.{$provider}.{$tier}") ?: config("ai.models.{$provider}.standard");
        $cfg = config("ai.providers.{$provider}");
        $client = match ($cfg['driver']) {
            'anthropic' => new AnthropicClient($cfg),
            'openai' => new OpenAiClient($cfg),
            default => throw new LlmRequestException("Unknown AI driver {$cfg['driver']}"),
        };

        return [$client, $model];
    }

    /** Cost in USD micro-units from configured prices (0 when not configured). */
    public function costMicros(string $model, int $in, int $out): int
    {
        $p = config("ai.prices.{$model}");
        if (! $p) {
            return 0;
        }

        return (int) round(($in * ($p['input'] ?? 0) + $out * ($p['output'] ?? 0)));
    }
}
