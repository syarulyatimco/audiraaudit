# AUDIRA — AI Agent Architecture & Permissions

Status: **All 12 agents in §3 are implemented (Phases 4–10).** §7 describes what is built
(per agent: tasks, tools, guards and how output is accepted); §1–§6 are the design principles.

## 1. Principles

1. **No unrestricted agent.** Each agent has an explicit tool allow-list and permission set.
2. **Agents propose, humans dispose.** Agents create *drafts* and *recommendations*. Every item in
   CLAUDE.md §9 goes through the Approval service and requires a human user id. Agents run as a
   service principal that is **structurally unable** to approve (it has no `approve.*` permission
   and `approvals.decided_by` must reference a human `users.id`).
3. **Scoped execution.** An `AiRun` is bound to exactly one firm and (normally) one engagement. Tools
   receive the run's scope and re-check it; the RLS session variable is set for the worker.
4. **Untrusted input.** Client documents, emails and portal messages are data, never instructions.
   They are wrapped in delimited blocks; tool calls originating from content are validated
   against the agent's allow-list and the run's scope, and outbound client communication always
   requires human approval (or a firm-approved template).
5. **Cited, not invented.** Any technical reference must cite a `standard_paragraph_id` from the
   versioned Audit Library. The citation guardrail rejects outputs whose citations do not
   resolve to a paragraph in the engagement's applicable library version.
6. **Provider independence.** Agents talk to `LlmClient` (interface). Drivers: Anthropic, OpenAI,
   Azure OpenAI, local (OpenAI-compatible). Model chosen by a routing table on task complexity.

## 2. Runtime components

```
User action / schedule / event
        │
        ▼
 ┌──────────────────┐   creates   ┌──────────┐
 │  AI Orchestrator │────────────▶│  ai_runs │ (status, scope, agent, input, output, cost)
 └────────┬─────────┘             └──────────┘
          │ selects agent + model, dispatches job on `ai` queue
          ▼
 ┌──────────────────┐  tool call  ┌──────────────────┐  service call  ┌───────────────┐
 │  Agent (worker)  │────────────▶│ Tool (permission │───────────────▶│ Module service│
 │  loop w/ LLM     │◀────────────│ + scope checked) │◀───────────────│ (same as UI)  │
 └────────┬─────────┘   result    └──────────────────┘                └───────────────┘
          │ structured output (JSON schema validated)
          ▼
 Guardrails: schema → citation check → materiality/number cross-check → PII redaction for logs
          │
          ▼
 Draft records + Approval request (if required) + ai_run_steps log + ai_usage metering
```

### Task lifecycle
`queued → running → awaiting_tool → (retrying) → needs_review → completed | failed | cancelled`

- Retries: exponential backoff (3 attempts) for provider/transport errors; **no** retry on guardrail
  failure — it is surfaced as `needs_review` with the failure reason.
- Idempotency key per run step so retried tool calls never double-write.
- Budget: per-firm monthly AI credits (from plan) checked before dispatch; hard stop at limit.
- Every prompt, tool call, tool result hash, model, tokens and cost is logged in `ai_run_steps`.

## 3. Agent catalogue

| # | Agent | Responsibilities | Tools (allow-list) | May write | Requires human approval for |
|---|---|---|---|---|---|
| 1 | Client Communication | Draft PBC request emails, reminders, answer status questions from portal | `pbc.read`, `pbc.draft_message`, `engagement.read_summary` | Draft messages | **Every** outbound message (unless firm-approved template) |
| 2 | Document Intelligence | Classify uploads, extract fields (invoices, bank statements, confirmations), match to PBC/sample items | `documents.read`, `documents.write_extraction`, `pbc.match` | Extractions, match suggestions | Low-confidence matches |
| 3 | Accounting & TB | Validate TB (balances, completeness), suggest account → FSLI mappings, flag anomalies | `tb.read`, `mapping.suggest`, `fsli.read` | Mapping suggestions | Mapping acceptance |
| 4 | Materiality | Recommend benchmark & % with rationale and cited guidance | `tb.read`, `fsli.read`, `entity.read`, `library.search` | Recommendation draft | **Materiality approval** (always) |
| 5 | Risk Assessment | Suggest risks of material misstatement by FSLI/assertion, fraud risk prompts | `entity.read`, `tb.read`, `analytics.run`, `library.search`, `risk.draft` | Draft risks | **Significant risks** |
| 6 | Audit Programme | Generate procedures responsive to assessed risks | `risk.read`, `library.search`, `programme.draft` | Draft procedures | Programme sign-off |
| 7 | Sampling | Propose method, compute sizes per documented methodology, perform reproducible selection | `population.read`, `materiality.read`, `sampling.compute`, `sampling.select` | Draft plan + seed + log | **Sampling plan approval** |
| 8 | Testing & Evidence | Compare evidence to sample items, tick-and-tie, record exceptions | `sample_evidence` (as built) | Draft test results (recorded only on acceptance) | Acceptance by the tester; reviewer sign-off |
| 9 | Findings | Summarise exceptions into findings using AUDIRA-computed amounts | `sample_exceptions`, `procedures_list`, `findings_list`, `materiality_current`, `fsli_list` (as built) | Draft findings | **Significant findings, material adjustments** (decided by people) |
| 10 | Completion | Draft checklist responses from the engagement record | `completion_checklist`, `findings_list`, `procedures_list`, `pbc_list`, `library_search` (as built) | Draft responses on open items | **Final conclusions, completion, opinion, sign-off** (decided by people) |
| 11 | Quality Review | Pre-review WPs for missing sign-offs, unresolved notes, missing conclusions, unlinked evidence | `review_status` (as built) | Draft review notes (raised by a person) | n/a (advisory) |
| 12 | Engagement Monitoring | Rules (scheduled, deterministic): deadlines, overdue requests, stale approvals and review notes, misstatements vs materiality; agent: on-request digest | `engagement_health` (as built) | Nothing (alerts/notifications are written by the rule engine) | n/a |

**Never permitted to any agent:** `approval.decide`, `report.sign`, `opinion.issue`, `user.*`,
`firm.*`, `billing.*`, cross-engagement reads (except Monitoring, which reads summaries only
within its firm).

## 4. Structured I/O

Each agent declares `inputSchema()` and `outputSchema()` (JSON Schema). Outputs that fail schema
validation are repaired once by the model, then fail to `needs_review`.

Example — Materiality agent output:
```json
{
  "benchmark": "profit_before_tax",
  "benchmark_amount_sen": 125000000,
  "percentage": 5.0,
  "overall_materiality_sen": 6250000,
  "performance_materiality_pct_of_om": 75,
  "clearly_trivial_pct_of_om": 5,
  "rationale": "…",
  "alternatives_considered": [{"benchmark": "revenue", "reason_rejected": "…"}],
  "citations": [{"standard_paragraph_id": 1234}],
  "confidence": "medium"
}
```
Percentages are *recommendations*; the engagement team edits and approves. The system never
presents any percentage as an ISA requirement.

## 5. Permissions model for AI

- `ai_agents` registry (code-defined) → allowed tools.
- Firm settings can **disable** agents or tools per firm; they can never widen the allow-list.
- A user can only start a run for an engagement they can access; the run inherits the
  *intersection* of the user's permissions and the agent's allow-list.

## 6. Usage tracking & billing

`ai_usage(firm_id, engagement_id, ai_run_id, provider, model, input_tokens, output_tokens,
cost_micros, credits, created_at)` aggregated monthly per firm; visible to firm admins and the
Superadmin dashboard.

## 7. Implemented in Phase 4

### Code map (`app/Ai`)
| Area | Classes | Notes |
|---|---|---|
| Gateway | `LlmClient`, `AnthropicClient`, `OpenAiClient`, `LlmRequest`/`LlmResponse`, `ModelRouter` | Canonical message blocks (text, tool_use, tool_result) translated per provider. `OpenAiClient` also serves Azure/local OpenAI-compatible endpoints via `OPENAI_BASE_URL`. HTTP 429/529/5xx and connection errors → `LlmTransportException` (retryable); other 4xx → `LlmRequestException` (not retried). |
| Routing | `config/ai.php` `models` | Tiers `fast` / `standard` / `complex` → model per provider (Anthropic: `claude-haiku-5-5` / `claude-sonnet-5-5` / `claude-opus-5-5`). Each agent task declares its tier. |
| Tools | `Tool`, `ToolRegistry`, `ToolContext` + `tb_list_accounts`, `fsli_list`, `mapping_history`, `tb_analytics` | Read-only, scoped to the run's engagement through `ToolContext`; each declares a JSON input schema and a required permission. |
| Agents | `Agent`, `AgentRegistry`, `AccountingTbAgent` | Agent = system prompt + task definitions (tier, required user permission, instruction, output schema) + a business-rule `guard()`. |
| Runtime | `AiOrchestrator`, `AgentRuntime`, `AiBudget`, `AiSettingsService`, `RunAgentJob` | Orchestrator gates and queues; runtime runs the tool loop on the `ai` queue inside `runForFirm()`. |
| Guardrails | `JsonSchema` + agent `guard()` | Structured output validation, one repair attempt, business-rule filtering. |

### Run lifecycle (as built)
`queued → running → completed | needs_review | failed` (`cancelled` reserved).

1. **Start** (`AiOrchestrator::start`): the user must hold the task's permission on the engagement
   (e.g. `engagement.map_accounts`), the engagement must be editable, and none of these may apply:
   no provider configured, firm has not opted in, agent disabled by the firm, monthly credits used.
   Only one active run per engagement/task (checked under a row lock). The run is created and
   `RunAgentJob` dispatched after commit; `ai.run_requested` is logged.
2. **Loop** (`AgentRuntime`): up to `ai.max_steps` (8) model turns. Tool calls are executed only if
   the tool is on the agent's allow-list **and** the requesting user holds the tool's permission
   (the run's effective tools are the intersection). Anything else is answered with an error to
   the model and logged as `tool_denied`. Tool input is schema-validated; results are capped at
   `ai.max_tool_result_chars`.
3. **Retries**: transport errors retry with `ai.retry_backoff_seconds` ([2, 5] → 3 attempts), each
   logged as a `retry` step; request errors fail the run immediately.
4. **Output**: the final message must be JSON matching the task's output schema; one repair turn is
   allowed, then the run ends `needs_review`.
5. **Guard**: business rules remove invalid items and record `violations`; any violation turns a
   run into `needs_review`.
6. **Finish**: status, output, tokens and credits saved; `ai.run_completed` / `ai.run_needs_review` /
   `ai.run_failed` logged with `actor_type = agent`.

Every model request/response, tool call/result/denial, retry, guardrail outcome and error is an
append-only `ai_run_steps` row; every model call writes an `ai_usage` row.

### Accounting & TB agent (`accounting_tb`)
| Task | Tier | Who may start | Tools used | Output | Guard |
|---|---|---|---|---|---|
| `suggest_mappings` | standard | `engagement.map_accounts` | `fsli_list`, `tb_list_accounts`, `mapping_history` | `suggestions[] {account_code, fsli_code, confidence, rationale}`, `needs_judgement[]`, `summary` | Removes suggestions for accounts that are not unmapped accounts of this engagement, unknown FSLI codes and duplicates |
| `review_tb` | standard | `engagement.edit_working_papers` | `tb_analytics` (AUDIRA-computed changes, opposite balances, totals) | `observations[] {fsli_code, category, severity, observation, suggested_procedure}` (max 15), `summary` | Removes unknown FSLI codes; flags `unverified_figures` — any amount ≥ 1,000 quoted by the model that does not appear in the tool data (years 19xx/20xx ignored) |

**Acceptance.** Mapping suggestions are shown on the Mapping tab with checkboxes (high confidence
pre-ticked). `POST …/ai-runs/{run}/accept-mappings` re-validates each selected code against the
current TB and FSLIs and applies it through `MappingService` with source `ai`; the agent itself
never writes mappings. Review observations are advisory and are labelled "for the team's
consideration — not conclusions".

### Firm controls and budget
- Firm admins (`ai.manage`) opt in on **Firm › AI settings** with an explicit acknowledgement that
  engagement data needed for a task is sent to the configured provider. They can disable agents.
- Monthly allowance = plan `ai_credits_per_user` × billable users; 1 credit = 1,000 tokens
  (`ai.tokens_per_credit`). Usage counts calendar months in Asia/Kuala_Lumpur. Starting a run is
  refused once the allowance is used. **The seeded plan credits (50/200/500) and `ai.prices` are
  placeholders** to be set commercially.

### Materiality agent (`materiality`) — Phase 5
| Task | Tier | Who may start | Tools | Output | Guard |
|---|---|---|---|---|---|
| `recommend_materiality` | complex | `engagement.prepare_materiality` | `fs_benchmarks`, `entity_profile`, `library_search` | benchmark, year, amount, OM/PM/CT %, rationales, alternatives, specifics, citations | Benchmark amount replaced by AUDIRA's figure if different; out-of-range or inconsistent percentages make it unusable; specifics ≥ overall removed; unretrieved/unpublished citations removed; wording that presents a percentage as required by a standard flagged |

"Use as draft" copies a usable recommendation into the open draft (source `ai`); the team edits it and submits it for the engagement partner's approval. Verified citations are added to D1.

### Risk Assessment agent (`risk_assessment`) — Phase 5
| Task | Tier | Who may start | Tools | Output | Guard |
|---|---|---|---|---|---|
| `suggest_risks` | complex | `engagement.manage_risks` | `tb_analytics`, `fsli_list`, `entity_profile`, `materiality_current`, `risks_list`, `library_search` | up to 12 risks with basis, level, FSLIs, assertions, factors, likelihood, magnitude, suggested significance, fraud, planned response, citations | Unknown FSLIs removed; assertion-level risks without FSLI/assertion removed; duplicates of the register removed; figures not in tool data flagged; citations verified |

The reviewer ticks which risks to add and which to record as significant; added risks are drafts in the register (source `ai`) and significance still needs partner approval. Verified citations are added to C1.

### Audit Programme agent (`audit_programme`) — Phase 6
`draft_procedures` (standard tier, `engagement.manage_programme`; tools `risks_list`, `fsli_list`, `materiality_current`, `procedures_list`, `library_search`). Every procedure must reference active risks; unknown risk references and FSLIs are removed, procedures without a valid risk are dropped, duplicates removed, sample sizes stated in steps are flagged. Accepted procedures enter the programme (source `ai`); citations go to A2.

### Sampling agent (`sampling`) — Phase 6
`recommend_parameters` (standard tier, `engagement.manage_sampling`; tools `population_summary`, `materiality_current`, `risks_list`, `library_search`). The plan comes from the run's `subject` input set by the user (validated against the engagement), never from the model. Output: confidence, TM/EM/key threshold or deviation rates, rationale. Guards: TM ≤ performance materiality, EM < TM, consistent rates, no stated sample size, no prescriptive claims. Accepting applies the parameters to the draft; AUDIRA recalculates the size and the plan still needs approval.

### Client Communication agent (`client_communication`) — Phase 7
`draft_request_message` (fast tier, `engagement.message_client`; tools `pbc_list`, `engagement_overview`). Drafts one reminder about requested/returned items. Unknown references removed; references to non-existent requests or to audit judgements (risks, materiality, findings, opinions) flagged. "Save to drafts" stores it as an agent-authored draft; only a person can send it.

### Document Intelligence agent (`document_intelligence`) — Phase 7
`classify_document` (fast tier, `engagement.manage_pbc`; tools `document_text`, `pbc_list`; subject = document). Text comes from clean versions only (`DocumentTextExtractor`). Output: type, summary, fields, suggested request, confidence. Amounts/references not present in the text and invalid dates are cleared; unknown requests cleared. Accepting records a `document_extractions` row and optionally links the document to the request; the agent never accepts a request.

### Testing & Evidence agent (`testing_evidence`) — Phase 8
`draft_test_results` (standard tier, `engagement.execute_procedures`; tool `sample_evidence`; subject = a sample being tested). The tool returns untested items with excerpts (≤3 per item) of clean engagement documents mentioning the item reference or linked by the auditor. Guards: item must be untested in this sample, the document must have been offered for that item, the quote must be a verbatim substring of the excerpt, and for monetary tests the amount relied on (recorded amount for "no exception", audited amount for an exception) must appear in the quote. Accepting records the results with the evidence link; the accepting auditor is `tested_by`. See ADR-033.

### Findings agent (`findings`) — Phase 8
`draft_findings` (standard tier, `engagement.manage_findings`; tools `sample_exceptions`, `procedures_list`, `findings_list`, `materiality_current`, `fsli_list`). Factual and projected misstatement amounts must equal figures AUDIRA computed for the referenced sample; unknown FSLI/procedure/plan references are cleared; misstatements without class, amount or FSLI are dropped; duplicates of recorded sample findings are dropped; comments on materiality, significance or the opinion and prescriptive claims are flagged. Accepted drafts become open findings (source `ai`); significance is never set by the agent.

### Completion agent (`completion`) — Phase 9
`draft_checklist` (standard tier, `engagement.manage_completion`; tools `completion_checklist`, `findings_list`, `procedures_list`, `pbc_list`, `library_search`). Drafts responses for open checklist items only. Guards: unknown/closed keys and duplicates removed; any reference to the audit opinion removes the response; materiality or going-concern conclusions and prescriptive claims flagged; figures not returned by tools flagged. Accepted drafts are stored on still-open items (source `ai`); a person marks each item done.

### Quality Review agent (`quality_review`) — Phase 9
`pre_review` (fast tier, `engagement.view`; tool `review_status`). Drafts review notes on specific working papers (unknown papers and opinion references removed) plus file observations. Accepting raises the notes in the accepting person's name through the normal review-note engine (requires `engagement.raise_review_notes`).

### Engagement Monitoring agent (`monitoring`) — Phase 10
`health_digest` (fast tier, `engagement.view`, offered to the engagement partner and manager; tool `engagement_health`). Summarises the rule-based alerts (see ADR-038) and progress counts into at most six priorities. Unknown alert kinds are cleared and anything referring to the opinion is removed. No write tools, nothing to accept. Scheduled monitoring itself is deterministic (`audira:monitor`) and consumes no credits.

### Audit Library retrieval (RAG)
`App\Ai\Rag\LibraryRetriever` searches **published** library versions only: PostgreSQL full-text search always, plus pgvector cosine similarity merged by reciprocal rank fusion when `AI_RAG_DRIVER=hybrid`, an embeddings endpoint (`AI_EMBEDDINGS_*`) is configured and the `library_paragraph_embeddings` table exists (created only where pgvector is installed). Publishing a version queues `EmbedLibraryVersion`; `php artisan audira:library-embed` backfills. The vector path is untested in the build sandbox (no pgvector).

### Not yet implemented
- PII redaction in step logs (steps are tenant-scoped and RLS-protected meanwhile).
- Cost reporting in currency (requires `ai.prices`).
