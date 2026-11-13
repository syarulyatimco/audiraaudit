<?php

namespace App\Ai\Rag;

use App\Enums\LibraryVersionStatus;
use App\Models\LibraryParagraph;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Retrieval over the Audit Library for AI agents (RAG). Only PUBLISHED versions are searched, so
 * agents can only cite authorised, Superadmin-curated text (CLAUDE.md §4, ADR-010).
 *
 *  - Full-text search (PostgreSQL tsvector) always works.
 *  - When ai.rag.driver = hybrid and pgvector embeddings exist, vector similarity results are
 *    merged with full-text results by reciprocal rank fusion.
 */
class LibraryRetriever
{
    public function __construct(private EmbeddingClient $embeddings) {}

    /** @return Collection<int, LibraryParagraph> best first */
    public function search(string $query, int $limit = 6, ?array $frameworkCodes = null): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }
        $limit = max(1, min($limit, 10));
        $lists = [$this->fullText($query, $limit * 2, $frameworkCodes)];
        if ($this->vectorEnabled()) {
            try {
                $lists[] = $this->vector($query, $limit * 2, $frameworkCodes);
            } catch (\Throwable $e) {
                Log::warning('Library vector search failed; using full-text results only.', ['error' => $e->getMessage()]);
            }
        }

        $scores = [];
        foreach ($lists as $ids) {
            foreach (array_values($ids) as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (60 + $rank + 1); // reciprocal rank fusion
            }
        }
        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, $limit);
        $models = LibraryParagraph::with('version.standard.framework')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $models->get($id))->filter()->values();
    }

    public function vectorEnabled(): bool
    {
        return config('ai.rag.driver') === 'hybrid' && $this->embeddings->isConfigured() && Schema::hasTable('library_paragraph_embeddings');
    }

    /** @return list<int> */
    private function fullText(string $query, int $limit, ?array $frameworks): array
    {
        return $this->published($frameworks)
            ->where(fn ($w) => $w->whereRaw("library_paragraphs.search @@ websearch_to_tsquery('english', ?)", [$query])
                ->orWhereRaw("library_paragraphs.search @@ plainto_tsquery('english', ?)", [$query]))
            ->orderByRaw("ts_rank(library_paragraphs.search, plainto_tsquery('english', ?)) desc", [$query])
            ->limit($limit)->pluck('library_paragraphs.id')->all();
    }

    /** @return list<int> */
    private function vector(string $query, int $limit, ?array $frameworks): array
    {
        $vector = '['.implode(',', $this->embeddings->embed([$query])[0]).']';

        return $this->published($frameworks)
            ->join('library_paragraph_embeddings as e', 'e.library_paragraph_id', '=', 'library_paragraphs.id')
            ->where('e.model', $this->embeddings->model())
            ->orderByRaw('e.embedding <=> ?::vector', [$vector])
            ->limit($limit)->pluck('library_paragraphs.id')->all();
    }

    private function published(?array $frameworks)
    {
        $q = DB::table('library_paragraphs')
            ->join('library_versions as v', 'v.id', '=', 'library_paragraphs.version_id')
            ->where('v.status', LibraryVersionStatus::Published->value);
        if ($frameworks) {
            $q->join('library_standards as s', 's.id', '=', 'v.standard_id')
                ->join('library_frameworks as f', 'f.id', '=', 's.framework_id')
                ->whereIn('f.code', $frameworks);
        }

        return $q;
    }
}
