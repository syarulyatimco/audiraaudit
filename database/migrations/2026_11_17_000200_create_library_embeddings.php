<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vector embeddings for Audit Library paragraphs (RAG). Requires the pgvector extension, which
 * production must provide (docs/ROADMAP.md risk #3). Where it is not available this migration
 * is a no-op and library search uses PostgreSQL full-text search only.
 *
 * Embedding dimensions must match the configured embedding model (ai.rag.dimensions).
 */
return new class extends Migration
{
    public function up(): void
    {
        $available = DB::selectOne("SELECT 1 AS ok FROM pg_available_extensions WHERE name = 'vector'");
        if (! $available) {
            Log::warning('pgvector is not installed on this PostgreSQL server; library search will use full-text search only.');

            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        $dims = (int) config('ai.rag.dimensions', 1536);
        DB::statement("CREATE TABLE library_paragraph_embeddings (
            id bigserial PRIMARY KEY,
            library_paragraph_id bigint NOT NULL REFERENCES library_paragraphs(id) ON DELETE CASCADE,
            model varchar(100) NOT NULL,
            content_sha256 char(64) NOT NULL,
            embedding vector({$dims}) NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE (library_paragraph_id, model)
        )");
        DB::statement('CREATE INDEX library_paragraph_embeddings_hnsw ON library_paragraph_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS library_paragraph_embeddings');
    }
};
