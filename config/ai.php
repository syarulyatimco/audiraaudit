<?php

/*
|--------------------------------------------------------------------------
| AUDIRA AI platform (docs/AI_AGENTS.md)
|--------------------------------------------------------------------------
| Provider-independent: agents ask for a complexity tier; the router maps it to a provider and
| model here. Nothing is called unless a provider is configured AND the firm has opted in.
*/

return [

    'default_provider' => env('AI_PROVIDER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => '2023-06-01',
            'timeout' => (int) env('AI_TIMEOUT', 120),
        ],
        // Any OpenAI-compatible Chat Completions endpoint (OpenAI, Azure OpenAI, self-hosted).
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => (int) env('AI_TIMEOUT', 120),
        ],
    ],

    // Complexity tier → model per provider. Override with env without code changes.
    'models' => [
        'anthropic' => [
            'fast' => env('AI_ANTHROPIC_FAST', 'claude-haiku-5-5'),
            'standard' => env('AI_ANTHROPIC_STANDARD', 'claude-sonnet-5-5'),
            'complex' => env('AI_ANTHROPIC_COMPLEX', 'claude-opus-5-5'),
        ],
        'openai' => [
            'fast' => env('AI_OPENAI_FAST'),
            'standard' => env('AI_OPENAI_STANDARD'),
            'complex' => env('AI_OPENAI_COMPLEX'),
        ],
    ],

    /*
    | Prices in USD per million tokens, for cost reporting only. Left at 0 until the operator
    | enters the contracted rates; credits (below) are what firms are metered on.
    */
    'prices' => [
        // 'claude-sonnet-5-5' => ['input' => 0, 'output' => 0],
    ],

    // 1 AI credit = this many tokens (input + output). Monthly allowance comes from the plan.
    'tokens_per_credit' => 1000,

    /*
    | Retrieval over the Audit Library. "fulltext" uses PostgreSQL full-text search only; "hybrid"
    | adds pgvector similarity (requires the pgvector extension and an OpenAI-compatible embeddings
    | endpoint). Only published library versions are ever searched.
    */
    'rag' => [
        'driver' => env('AI_RAG_DRIVER', 'fulltext'),
        'base_url' => env('AI_EMBEDDINGS_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('AI_EMBEDDINGS_API_KEY'),
        'model' => env('AI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('AI_EMBEDDINGS_DIMENSIONS', 1536),
    ],

    'max_steps' => 8,                       // LLM ↔ tool round-trips per run
    'retry_backoff_seconds' => [2, 5],      // transient provider errors (network, 429, 5xx)
    'max_output_tokens' => 4096,
    'max_tool_result_chars' => 60000,

    /*
    | Agent registry. Each agent lists the ONLY tools it may call (allow-list) and, per task,
    | the engagement permission the requesting user must hold. Firms may disable agents; they
    | can never widen these lists.
    */
    'agents' => [
        'accounting_tb' => [
            'class' => App\Ai\Agents\AccountingTbAgent::class,
            'tools' => ['tb_list_accounts', 'fsli_list', 'mapping_history', 'tb_analytics'],
        ],
        'materiality' => [
            'class' => App\Ai\Agents\MaterialityAgent::class,
            'tools' => ['fs_benchmarks', 'entity_profile', 'library_search'],
        ],
        'risk_assessment' => [
            'class' => App\Ai\Agents\RiskAssessmentAgent::class,
            'tools' => ['tb_analytics', 'fsli_list', 'entity_profile', 'materiality_current', 'risks_list', 'library_search'],
        ],
        'audit_programme' => [
            'class' => App\Ai\Agents\AuditProgrammeAgent::class,
            'tools' => ['risks_list', 'fsli_list', 'materiality_current', 'procedures_list', 'library_search'],
        ],
        'sampling' => [
            'class' => App\Ai\Agents\SamplingAgent::class,
            'tools' => ['population_summary', 'materiality_current', 'risks_list', 'library_search'],
        ],
        'client_communication' => [
            'class' => App\Ai\Agents\ClientCommunicationAgent::class,
            'tools' => ['pbc_list', 'engagement_overview'],
        ],
        'document_intelligence' => [
            'class' => App\Ai\Agents\DocumentIntelligenceAgent::class,
            'tools' => ['document_text', 'pbc_list'],
        ],
        'testing_evidence' => [
            'class' => App\Ai\Agents\TestingEvidenceAgent::class,
            'tools' => ['sample_evidence'],
        ],
        'findings' => [
            'class' => App\Ai\Agents\FindingsAgent::class,
            'tools' => ['sample_exceptions', 'procedures_list', 'findings_list', 'materiality_current', 'fsli_list'],
        ],
        'completion' => [
            'class' => App\Ai\Agents\CompletionAgent::class,
            'tools' => ['completion_checklist', 'findings_list', 'procedures_list', 'pbc_list', 'library_search'],
        ],
        'quality_review' => [
            'class' => App\Ai\Agents\QualityReviewAgent::class,
            'tools' => ['review_status'],
        ],
        'monitoring' => [
            'class' => App\Ai\Agents\MonitoringAgent::class,
            'tools' => ['engagement_health'],
        ],
    ],
];
