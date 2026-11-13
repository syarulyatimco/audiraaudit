<?php

namespace App\Ai\Rag;

use App\Ai\Gateway\LlmRequestException;
use App\Ai\Gateway\LlmTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Text embeddings through an OpenAI-compatible /embeddings endpoint (OpenAI, Azure OpenAI or a
 * self-hosted model). Used only for Audit Library retrieval; never sent engagement data other
 * than the search query an agent formulates.
 */
class EmbeddingClient
{
    public function isConfigured(): bool
    {
        return filled(config('ai.rag.api_key')) && filled(config('ai.rag.model'));
    }

    public function model(): string
    {
        return (string) config('ai.rag.model');
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        try {
            $response = Http::withToken((string) config('ai.rag.api_key'))
                ->timeout(60)
                ->post(rtrim((string) config('ai.rag.base_url'), '/').'/embeddings', [
                    'model' => $this->model(),
                    'input' => array_values($texts),
                    'dimensions' => (int) config('ai.rag.dimensions'),
                ]);
        } catch (ConnectionException $e) {
            throw new LlmTransportException('Embedding service unreachable: '.$e->getMessage(), previous: $e);
        }
        if ($response->status() === 429 || $response->serverError()) {
            throw new LlmTransportException("Embedding service HTTP {$response->status()}");
        }
        if (! $response->successful()) {
            throw new LlmRequestException("Embedding service HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        return collect($response->json('data', []))->sortBy('index')->pluck('embedding')->values()->all();
    }
}
