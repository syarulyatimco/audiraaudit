<?php

namespace App\Ai\Tools;

/**
 * A narrowly-scoped capability an agent may call. Tools read through the same services the UI
 * uses, are bound to the run's engagement via ToolContext, and never modify audit data.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /** JSON Schema for the tool's input. */
    public function inputSchema(): array;

    /** Engagement permission the REQUESTING USER must hold for the agent to use this tool. */
    public function requiredPermission(): string;

    /** @return array<string, mixed> JSON-serialisable result */
    public function handle(ToolContext $ctx, array $input): array;
}
