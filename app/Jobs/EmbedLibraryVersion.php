<?php

namespace App\Jobs;

use App\Ai\Rag\EmbeddingClient;
use App\Ai\Rag\LibraryRetriever;
use App\Models\LibraryParagraph;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Embed the paragraphs of a published library version for vector retrieval. Platform-level data
 * (no tenant); skipped unless pgvector and an embedding model are configured.
 */
class EmbedLibraryVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $versionId)
    {
        $this->onQueue('ai');
    }

    public function handle(EmbeddingClient $client, LibraryRetriever $retriever): void
    {
        if (! $retriever->vectorEnabled()) {
            return;
        }
        $model = $client->model();
        LibraryParagraph::where('version_id', $this->versionId)->orderBy('id')->chunk(64, function ($chunk) use ($client, $model) {
            $texts = $chunk->map(fn (LibraryParagraph $p) => trim($p->heading."\n".$p->body))->all();
            $todo = $chunk->filter(function (LibraryParagraph $p, $i) use ($model, $texts) {
                $hash = hash('sha256', $texts[$i]);

                return ! DB::table('library_paragraph_embeddings')->where(['library_paragraph_id' => $p->id, 'model' => $model, 'content_sha256' => $hash])->exists();
            });
            if ($todo->isEmpty()) {
                return;
            }
            $vectors = $client->embed($todo->map(fn ($p, $i) => $texts[$i])->values()->all());
            foreach ($todo->values() as $n => $p) {
                $text = trim($p->heading."\n".$p->body);
                DB::statement(
                    'INSERT INTO library_paragraph_embeddings (library_paragraph_id, model, content_sha256, embedding) VALUES (?, ?, ?, ?::vector)
                     ON CONFLICT (library_paragraph_id, model) DO UPDATE SET content_sha256 = EXCLUDED.content_sha256, embedding = EXCLUDED.embedding, created_at = now()',
                    [$p->id, $model, hash('sha256', $text), '['.implode(',', $vectors[$n]).']']
                );
            }
        });
    }
}
