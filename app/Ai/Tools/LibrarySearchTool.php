<?php

namespace App\Ai\Tools;

use App\Ai\Rag\LibraryRetriever;
use App\Models\LibraryParagraph;
use Illuminate\Support\Str;

class LibrarySearchTool implements Tool
{
    public function __construct(private LibraryRetriever $retriever) {}

    public function name(): string
    {
        return 'library_search';
    }

    public function description(): string
    {
        return "Search the firm's Audit Library (published, authorised versions of standards and legislation) for paragraphs relevant to a topic. Returns paragraph_id, citation and an excerpt. You may only cite paragraph_ids returned by this tool. If nothing relevant is returned, cite nothing.";
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['query'], 'additionalProperties' => false, 'properties' => [
            'query' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
        ]];
    }

    public function requiredPermission(): string
    {
        return 'engagement.view';
    }

    public function handle(ToolContext $ctx, array $input): array
    {
        $hits = $this->retriever->search($input['query'], $input['limit'] ?? 5);

        return [
            'paragraphs' => $hits->map(fn (LibraryParagraph $p) => [
                'paragraph_id' => $p->id,
                'citation' => $p->citation(),
                'standard' => $p->version->standard->title,
                'heading' => $p->heading,
                'excerpt' => Str::limit($p->body, 700),
            ])->all(),
            'note' => $hits->isEmpty() ? 'No published library paragraphs matched. Do not cite any standard.' : null,
        ];
    }
}
