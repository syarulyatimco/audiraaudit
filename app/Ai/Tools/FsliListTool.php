<?php

namespace App\Ai\Tools;

class FsliListTool implements Tool
{
    public function name(): string
    {
        return 'fsli_list';
    }

    public function description(): string
    {
        return 'List the financial statement line items (FSLIs) configured for this engagement: code, name and classification. Only these codes may be used in mapping suggestions.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function requiredPermission(): string
    {
        return 'engagement.view';
    }

    public function handle(ToolContext $ctx, array $input): array
    {
        return ['fslis' => $ctx->engagement->fslis()->get()->map(fn ($f) => [
            'code' => $f->code, 'name' => $f->name, 'classification' => $f->classification->value,
        ])->all()];
    }
}
