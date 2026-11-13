# AUDIRA — Development Roadmap & Progress Tracker

Each phase ends deployable, tested and documented (CLAUDE.md §15). A phase does not start while
critical defects from the previous one remain open.

## Progress tracker

| Phase | Scope | Status |
|---|---|---|
| 0 | Architecture, ERD, agent design, workflow, security, roadmap | ✅ Done (2026-11-13) |
| 1 | Foundation: tenancy + RLS, auth, firms, users & invitations, RBAC, clients & acceptance approval, engagements & teams, approvals engine, activity log, plans/subscriptions, Superadmin | ✅ Done (2026-11-13) |
| 2 | Working-paper framework, document storage (AV scan, versioning, hashing), Audit Library (versioned frameworks/standards/sources), Entity Understanding, MFA | ✅ Done (2026-11-14) |
| 3 | Trial balance import & validation, account mapping, FSLI templates, lead schedules, adjustment journal | ✅ Done (2026-11-15) |
| 4 | AI platform: LLM gateway (multi-provider), orchestrator, agent runtime, tool permissions, guardrails, usage metering; Accounting & TB agent (RAG on pgvector moved to Phase 5) | ✅ Done (2026-11-16) |
| 5 | Materiality (all four levels, versioning, approval), Risk Assessment, Audit Strategy + Materiality & Risk agents; Audit Library retrieval (full-text, pgvector hybrid when available) | ✅ Done (2026-11-17) |
| 6 | Audit Programme + Sampling (all methods, reproducible selection, projection) + agents | ✅ Done (2026-11-18) |
| 7 | Client portal, PBC management, Client Communication & Document Intelligence agents | ✅ Done (2026-11-19) |
| 8 | Audit testing, evidence linking, findings, misstatement evaluation, adjustments + agents | ✅ Done (2026-11-20) |
| 9 | Completion, quality review (EQR), FS preparation, audit reporting & sign-off + agents | ✅ Done (2026-11-21) |
| 10 | Billing integration (payment gateway, invoices, tax), Monitoring agent, Superadmin analytics, break-glass support access, performance & pen-test hardening | ✅ Done (2026-11-22) — external pen test and go-live checklist pending (SECURITY.md) |

## Phase 1 — detailed scope (delivered)
- [x] Multi-tenant core: `TenantContext`, `FirmScope`, `BelongsToFirm`, RLS policies, middleware
- [x] Authentication: login (throttled), logout, firm registration (trial), invitation acceptance, password change
- [x] Firm profile management (SSM no., AF no., default framework)
- [x] User management: invite, change role, deactivate/reactivate, last-admin protection, seat count
- [x] Client management: CRUD, contacts, acceptance assessment → approval request → decision
- [x] Engagement management: create (accepted clients only), team & roles, status transitions, membership-based access
- [x] Generic approvals engine with segregation of duties and history
- [x] Immutable activity log + firm activity screen
- [x] Plans & subscriptions: configurable pricing, min paid users, billing estimate
- [x] Superadmin: plans editor, firm list, suspend/reactivate, change plan
- [x] Feature tests on PostgreSQL incl. RLS isolation

## Phase 2 — detailed scope (delivered)
- [x] MFA: TOTP with QR enrolment, encrypted secrets, replay protection, single-use hashed recovery codes; mandatory for partners, firm admins and Superadmin; challenge on every new session
- [x] Documents: private disk with opaque keys, extension + content-sniffed MIME allow-list, SHA-256 hashing, immutable versions (DB trigger), queued malware scan (clamd in production, signature scanner in development), download only when clean, archive-not-delete
- [x] Working papers: index by section (P/A/B/C/D/F/X/Z), preparer/reviewer sign-offs bound to a content hash, automatic invalidation on any change (incl. evidence re-versioning and citations) with history, review notes (raise → respond → clear) blocking review, evidence links, library citations
- [x] Entity understanding (B1): 15 structured sections following ISA 315 (Revised 2019) topics, signed off through the working-paper engine
- [x] Audit Library: platform frameworks → standards → versions → paragraphs; draft/publish/supersede; source title/URL/file hash required; CSV import (all-or-nothing); published paragraphs immutable (DB trigger); full-text search; firm read-only browsing; frameworks seeded, **no standard text seeded**
- [x] Engagement tabs (Overview · Understanding · Working papers · Documents); locked engagements read-only
- [x] 80 feature tests on PostgreSQL

### Still open from Phase 2 scope
- S3 driver package (`league/flysystem-aws-s3-v3`) not installed in this sandbox — disk config is ready; install and set `AUDIRA_DOCUMENTS_DISK=s3_documents`.
- ClamAV daemon not available in this sandbox — the `clamd` driver is implemented but untested here; production must set `AUDIRA_SCANNER=clamd`.
- Re-authentication step for sign-off of audit report (Phase 9).

## Phase 3 — detailed scope (delivered)
- [x] Trial balance import (CSV and XLSX, first worksheet): two-step upload → column confirmation with automatic header/column detection and live preview; source file kept as hashed, scanned evidence
- [x] Strict validation: accounting amount formats, duplicate codes, invalid amounts (row-level errors), subtotal rows skipped with warnings, debits = credits to the sen (also a DB constraint on active imports)
- [x] Current and prior-year TBs; re-import supersedes the active TB; imported lines immutable (DB trigger)
- [x] FSLI management: platform templates (corporate MFRS/MPERS; strata JMB/MC chosen automatically for JMB/MC clients), engagement-level add/rename/delete
- [x] Account mapping by account code (persists across re-imports, applies to both years); suggestions from the client's earlier engagements (carried forward) and account-name rules, applied only when a person accepts them; source recorded per mapping
- [x] Lead schedules per FSLI (prior year, unadjusted, adjustments, adjusted, change) and draft SOFP / P&L with integrity checks (TB balances; assets = liabilities + equity + profit; unmapped accounts); opposite-balance flags
- [x] Lead-schedule working papers whose sign-offs cover the FSLI figures (invalidated when TB, mapping or posted adjustments change)
- [x] Adjustment journal (AJE / RJE / CJE): balanced lines validated against the current TB, draft → posted → voided; preparer cannot post (service + DB constraint); posted lines frozen (DB trigger)
- [x] Adjusted TB CSV export with spreadsheet-formula neutralisation
- [x] 122 tests (unit + feature) on PostgreSQL

### Deferred to later phases (by design)
- Corrected vs uncorrected classification, summary of audit differences and approval of material adjustments → Phase 8 (needs materiality, Phase 5).
- AI mapping suggestions (`source = ai`) → Phase 4 Accounting & TB agent.
- Firm-level custom FSLI templates and MPSAS / cooperative templates → when authorised captions are confirmed.

## Phase 4 — detailed scope (delivered)
- [x] Provider-independent LLM gateway (Anthropic Messages API, OpenAI-compatible Chat Completions incl. Azure/local endpoints), canonical tool-use messages, transport vs request error classes
- [x] Model routing by task tier (fast / standard / complex), configurable in `config/ai.php`
- [x] Orchestrator: permission, engagement-lock, firm opt-in, agent enablement, credit budget and duplicate-run checks; queued execution on the `ai` queue inside the firm's tenant context
- [x] Agent runtime: bounded tool loop (8 steps), allow-list ∩ user permissions, tool input schema validation, retries with backoff, one structured-output repair, business-rule guardrails, `needs_review` outcome
- [x] Append-only execution log (`ai_run_steps`) and metering (`ai_usage`) with RLS; credits = tokens / 1,000
- [x] Firm AI settings: opt-in with data-handling acknowledgement, per-agent disable, monthly usage; Superadmin AI platform card
- [x] Accounting & TB agent: mapping suggestions (accepted per account by a person, source `ai`) and analytical TB review with unverified-figure flagging; engagement "AI assistant" tab with run log and run detail
- [x] 137 tests (unit + feature) on PostgreSQL; provider calls faked in tests

### Deferred / open from Phase 4
- RAG on pgvector → Phase 5 (see AI_AGENTS.md §7); requires pgvector in staging/production.
- Plan AI credit allowances (seeded 50 / 200 / 500 per user are **placeholders**) and per-model prices (`ai.prices`, currently 0) → commercial decision.
- A real provider API key and a data-processing agreement with the chosen provider before enabling AI for client data.

## Phase 5 — detailed scope (delivered)
- [x] Materiality: overall, performance, clearly trivial and specific materiality; benchmarks from the adjusted TB (current/prior year) or a documented manual basis; amounts computed server-side; rationale per level; versions planning → revised → final; approved versions frozen (DB trigger) and superseded only by an approved revision; drift warning when the TB benchmark changes
- [x] Engagement-scoped approvals: decider must be the engagement partner (config per approval type); requests refused when nobody else could approve; approvals visible only to people who can see the engagement
- [x] Risk assessment: risk register (FS / assertion level, FSLIs, assertions, inherent risk factors, likelihood × magnitude spectrum, fraud, controls reliance, planned response); presumed fraud risks with ISA 240 rules (override cannot be withdrawn or made non-significant; revenue presumption rebuttable with reasons); significant-risk approval and re-approval after substantive change; withdrawal instead of deletion; coverage check against performance materiality
- [x] Audit strategy (ISA 300 topics, key dates, substantive/combined approach) with planning summary
- [x] Planning working papers A1 / C1 / D1 attesting to strategy, risks and materiality, with sign-off gates
- [x] Materiality agent (benchmark figures corrected to AUDIRA's, percentage-as-requirement wording flagged, specific materiality checked) and Risk Assessment agent (FSLI/assertion validation, duplicates removed, unverified figures flagged); accepted output becomes drafts, and verified citations are added to D1 / C1
- [x] Audit Library retrieval for agents: published paragraphs only; PostgreSQL full-text search, plus pgvector similarity with reciprocal rank fusion when `AI_RAG_DRIVER=hybrid`; citations allowed only for paragraphs retrieved in the same run
- [x] 151 tests (unit + feature) on PostgreSQL

### Deferred / open from Phase 5
- pgvector path (migration, `EmbedLibraryVersion`, `audira:library-embed`, hybrid retrieval) is implemented but **untested here** — pgvector is not installed in the build sandbox. Test on staging with pgvector before enabling `AI_RAG_DRIVER=hybrid`.
- Firm methodology ranges for materiality percentages (shown as guidance, never as ISA requirements) → firm settings, when a firm supplies its methodology.
- Linking materiality to sampling and misstatement evaluation → Phases 6 and 8 read `Engagement::currentMateriality()`.
- Detailed procedures responding to each risk → Phase 6 audit programme.

## Phase 6 — detailed scope (delivered)
- [x] Audit programme: procedures (nature, timing, assertions, FSLI, assignee, budget hours, status, conclusion) traced to the risks they respond to; risk coverage panel; withdrawal instead of deletion
- [x] Working paper A2 (audit programme) with gates: every active risk has a response, every significant risk has a substantive procedure, every procedure is assigned
- [x] Sampling plans for monetary and attribute tests; methods MUS, random, systematic, stratified (statistical or non-statistical), haphazard and judgemental (always non-statistical, enforced in the database)
- [x] Population upload (CSV/XLSX) with SHA-256 hash and completeness reconciliation to the FSLI balance or a stated control total (differences must be explained)
- [x] Documented sample sizes: MUS (Poisson factor, firm expansion factor when misstatement is expected), attributes (Poisson), non-statistical (firm assurance factor + rationale); key items tested in full; tolerable misstatement capped at performance or specific materiality
- [x] Approval by the engagement partner or manager before selection; population and parameters frozen once approved (DB triggers)
- [x] Reproducible selection (stored seed, algorithm, population hash; re-performed on screen); immutable selection log; manual selection with a reason per item for judgemental/haphazard
- [x] Results per item and evaluation: MUS cell evaluation (projected, basic precision, incremental allowance, upper limit), ratio projection (by stratum where stratified), attributes upper deviation limit; flags when tolerable is exceeded or results exceed planning expectations
- [x] Audit Programme agent (risk-traced procedures, invented risks/FSLIs removed, sample sizes in steps flagged) and Sampling agent (parameters only; AUDIRA computes the size)
- [x] 163 tests (unit + feature) on PostgreSQL

### Deferred / open from Phase 6
- Firm-level methodology tables (expansion / assurance factors by risk level) — entered per plan for now, with rationale.
- Linking exceptions to findings and the summary of audit differences → Phase 8.
- Population extraction directly from client ledgers (portal uploads) → Phase 7.

## Phase 7 — detailed scope (delivered)
- [x] Client portal: invitations with hashed single-use tokens, free client users scoped to one client, separate portal layout and routes; client users are redirected away from all firm pages; firm admins/partners/managers grant and remove access
- [x] PBC requests: draft → requested (portal notification) → submitted (client upload) → accepted / returned with a note (client notified) / cancelled; due dates and overdue flags; drafts and cancelled items never shown to clients
- [x] Client uploads become engagement documents (category client provided) through the existing pipeline: hashed, content-type checked, malware scanned, versioned
- [x] Correspondence: outbound messages are drafts until a team member sends them (DB check), sent messages immutable (trigger); client replies stored as untrusted inbound messages; email notifications carry no audit content
- [x] Client Communication agent (fast tier): drafts reminders from PBC data; invented references removed; mentions of risks, materiality, findings or opinions flagged; saved as a draft only
- [x] Document Intelligence agent (fast tier): text from clean documents (txt/csv, xlsx, docx, PDF via pdftotext); classification, summary, fields that must appear in the text, suggested request; accepted extractions recorded per document version
- [x] 168 tests on PostgreSQL

### Deferred / open from Phase 7
- OCR for scanned PDFs and images (needs an OCR engine such as Tesseract or a provider's vision API, with a data-processing agreement).
- Bulk PBC templates per industry (e.g. JMB/MC, cooperatives) → configurable templates when the firm supplies them.
- MFA for portal users is optional; enforce if firms require it.

## Phase 8 — detailed scope (delivered)
- [x] Evidence: per-item evidence document on sample results (engagement documents only); procedure working papers in the fieldwork section, opened once per procedure, with the existing sign-off, review-note and evidence-link engine
- [x] Findings register: misstatements (factual / judgemental / projected; recorded − audited; FSLI required; proposed → corrected by a posted adjustment / uncorrected with management's reason), control deficiencies (deficiency / significant deficiency, significant always communicated), other matters; resolve / withdraw with reasons; DB checks back every rule
- [x] Significant findings approved by the engagement partner; changes after a decision require re-approval
- [x] Record as findings from evaluated monetary samples (factual + projected remainder from the stored evaluation; once per sample)
- [x] Summary of audit differences: clearly trivial filter, by FSLI and class, specific materiality where set, net and gross aggregate vs overall and performance materiality, management-response tracking
- [x] Material-adjustment approval (configurable basis; lines-hash bound; edit clears approval)
- [x] Testing & Evidence agent and Findings agent with evidence/amount guards and human acceptance
- [x] 174 tests on PostgreSQL

### Deferred / open from Phase 8
- Material-adjustment basis is a firm-methodology choice (default performance materiality) — confirm per firm.
- Tests of controls are recorded through attribute samples and findings; a dedicated controls matrix (walkthroughs, ToD/ToE) can follow if firms need it.
- Profit-effect netting on the summary of audit differences would need the double entry per misstatement (proposed journal) — see ADR-032.

## Phase 9 — detailed scope (delivered)
- [x] Completion checklist (12 ISA-topic prompts, firm-extendable config), done / not applicable with response, locked after conclusions are submitted
- [x] Final conclusions with hash-bound partner approval; EQR recorded by the assigned EQR reviewer; completion approval with live gates
- [x] Financial statements (SOFP, SOPL, prior year, checks, print view, snapshot at sign-off)
- [x] Audit report: proposed opinion and modifications, firm templates with placeholders, opinion approval and report sign-off with re-authentication, signed report frozen, engagement signed off and locked
- [x] Completion agent and Quality Review agent (advisory, human acceptance)
- [x] 180 tests on PostgreSQL

### Deferred / open from Phase 9
- Firms must load their approved report wording (templates) — AUDIRA ships none (ADR-035).
- Notes to the financial statements, SOCE, cash flow statement and framework-specific formats (ADR-036).
- Archiving after sign-off (retention period still to be confirmed — risk #6).
- PDF rendering of the report and statements (print views are provided; a PDF engine such as headless Chrome would be added at deployment).

## Phase 10 — detailed scope (delivered)
- [x] Invoices: per-user monthly billing from the subscription snapshot, minimum seats, sequential numbering, idempotent per period, frozen amounts, void/reissue, payments recorded by platform staff, daily billing run with past-due and restoration
- [x] Pluggable payment gateway (manual bank-transfer driver); tax line only when configured
- [x] Engagement monitoring rules, alerts, notifications, overview panel; Monitoring agent digest
- [x] Superadmin analytics (billing, usage, engagement counts, AI runs, support grants)
- [x] Break-glass support access (firm-granted, ≤72 h, metadata-only, logged)
- [x] Security headers, no-store for authenticated pages, supporting indexes
- [x] 186 tests on PostgreSQL

### Open decisions for go-live
- Payment gateway provider (FPX/card) and the platform's tax position (ADR-037).
- AI credit allowances and prices (`config/ai.php` placeholders), provider key and data-processing agreement.
- pgvector in production; Audit Library content licences (ISA/MFRS).
- OCR engine for scanned evidence.
- Record retention period; external penetration test; items in the SECURITY.md pre-production checklist.

## Identified conflicts, gaps and risks

| # | Item | Type | Impact | Recommendation |
|---|---|---|---|---|
| 1 | Licensed standards content (ISA via IFAC/MIA, MFRS/IFRS via IFRS Foundation/MASB) | Commercial/legal risk | RAG on full standards text needs licences | Obtain licences or store paragraph references + firm-uploaded licensed copies; library supports both |
| 2 | "AI communicates with clients" vs "client messages are untrusted" | Requirement tension | Prompt injection, mis-statements to clients | All outbound AI messages need human approval or approved templates (adopted) |
| 3 | pgvector not available in the current dev sandbox | Environment | RAG cannot be tested here | Require pgvector in staging/prod (managed PG supports it); RAG moved to Phase 5 with a migration guarded by an extension check |
| 4 | MFA not listed in CLAUDE.md | Missing requirement | Partner accounts sign reports | Add mandatory TOTP for partners/admins (Phase 2) |
| 5 | Payment gateway, invoicing & tax treatment of subscription not specified | Missing requirement | Billing cannot go live | Decide gateway (e.g. Stripe / local FPX provider) and tax position with an adviser before Phase 10 |
| 6 | Record retention & archiving period | Missing requirement | Legal exposure | Make retention configurable; confirm period with MIA By-Laws / legal advice |
| 7 | Superadmin support needs vs client confidentiality | Requirement tension | Trust | No default access; firm-granted, time-boxed, logged break-glass (Phase 10) |
| 8 | Blade + Alpine for grid-heavy screens (TB mapping, sampling) | Technical risk | UX/perf on 10k+ row TBs | Server-side pagination + Alpine islands; revisit (with approval) if insufficient |
| 9 | Per-user billing vs free client portal users | Abuse risk | Firms could create staff as "client" users | Client users are restricted to portal routes only and cannot be assigned to engagements |
| 10 | Packagist & npm blocked in the build sandbox | Environment | Lock file not reproducible here | Dependencies were sourced from upstream GitHub tags; regenerate `composer.lock` with `composer update` on a normal network |
| 11 | JMB/MC & SKM reporting specifics | Domain gap | Need authoritative templates | Source from Strata Management Act 2013 / SKM guidelines into the library; do not fabricate |
| 12 | Engagement quality review applicability (listed/PIE vs SME) | Domain | Workflow rule | Make EQR requirement a firm policy setting per engagement |
| 13 | Library content licensing (see #1) blocks real standard text | Commercial | Agents can only cite what is loaded; with an empty library they cite nothing | Load licensed text (or firm-uploaded licensed copies) before relying on AI citations; agents already refuse to cite anything else |
| 14 | Client data sent to an external LLM provider | Legal/confidentiality | PDPA 2010 and client confidentiality obligations | Firm opt-in with acknowledgement (built); sign a DPA with the provider, prefer zero-retention endpoints or a regional/self-hosted model; document in engagement letters |
