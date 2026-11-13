# AUDIRA — Architectural Decision Records

Format: context → decision → consequences. Newest last.

## ADR-001 Modular monolith on Laravel 12 (2026-11-13)
**Context.** CLAUDE.md §10 prescribes Laravel, Blade, Alpine, Tailwind, PostgreSQL, Redis.
**Decision.** One Laravel application, modules separated by namespace (`app/Services/<Module>`,
controllers per module folder), shared services used by both web UI and future REST API.
**Consequences.** Single deployable, single transaction boundary; module boundaries enforced by review now and an architecture test from Phase 2.

## ADR-002 Shared-schema multi-tenancy with defence in depth (2026-11-13)
**Context.** Strict isolation between audit firms is non-negotiable; small firms make
database-per-tenant operationally expensive.
**Decision.** `firm_id` on every tenant row + application `FirmScope` (fail-closed) + PostgreSQL
RLS (enabled **and forced**) keyed on `app.firm_id`, with the app connecting as a
`NOSUPERUSER NOBYPASSRLS` role. System mode (`TenantContext::runAsSystem`) is the only bypass and its call sites are enumerated in SECURITY.md.
**Consequences.** A missing `where firm_id` in application code cannot leak data. Every new tenant table must be added to an RLS migration (checklist item in TESTING.md). `set_config` values follow transaction rollback semantics — the middleware re-syncs at request boundaries.

## ADR-003 Users are not globally tenant-scoped (2026-11-13)
**Context.** Authentication must load a user before the tenant is known.
**Decision.** `users` has no global scope or RLS; controllers resolve users through
`$firm->staff()` or explicit `firm_id` checks (404 on mismatch). Email is globally unique (case-insensitive index), so one person = one firm account.
**Consequences.** A user cannot belong to two firms. If cross-firm consultants are needed later, introduce a `firm_memberships` table.

## ADR-004 Generic approvals engine (2026-11-13)
**Context.** CLAUDE.md §9 lists ten human-approval gates across many modules.
**Decision.** One polymorphic `approvals` table + `ApprovalService` + `Approvable` contract.
Segregation of duties enforced in the service *and* by a DB CHECK (`decided_by <> requested_by`).
One pending request per subject/type (partial unique index). Decisions are immutable rows.
**Consequences.** New modules (materiality, sampling, sign-off) only implement `Approvable` and add a decider rule in `config/audira.php`.

## ADR-005 Superadmin has no access to tenant audit data (2026-11-13)
**Context.** Audit files are confidential; platform staff need firm/billing administration only.
**Decision.** Superadmin is a separate user type with its own routes running in system mode,
limited to firms, plans and subscriptions. Firm routes reject platform users.
**Consequences.** Support cases that need data access require a future break-glass flow (Phase 10).

## ADR-006 Prices in integer sen with subscription snapshots (2026-11-13)
**Decision.** `price_per_user_sen` integers; subscriptions snapshot price and minimum users so a Superadmin price change never alters a running period.

## ADR-007 Front-end build without Vite/npm at runtime (2026-11-13)
**Context.** The build sandbox has no npm registry access; CLAUDE.md asks for Blade + Alpine + Tailwind.
**Decision.** Tailwind 3.4 standalone CLI compiles `resources/css/app.css` → `public/css/app.css`;
Alpine 3.15 is served from `public/js/alpine.min.js` (built from upstream source). Compiled assets are committed so the app runs without Node. `package.json` lets developers rebuild with npm normally (`npm install && npm run build`).
**Consequences.** No HMR; acceptable for a server-rendered app. Revisit Vite if JS grows substantially.

## ADR-008 Dependencies sourced from upstream git in the build sandbox (2026-11-13)
**Context.** Packagist was unreachable from the development sandbox.
**Decision.** Phase 1 was built and tested against packages cloned from their official GitHub
repositories at tagged releases (Laravel 12.72, PHPUnit 11.5). `composer.lock` is **not** shipped;
run `composer update` on a normal network to produce a lock file from Packagist.
**Consequences.** First install resolves fresh versions within the same constraints; rerun the test suite after installing.

## ADR-009 Sign-offs attest to a content hash (2026-11-14)
**Context.** A review sign-off is worthless if the paper can change afterwards.
**Decision.** Each `wp_signoffs` row stores the SHA-256 of everything it attests to (title, objective, work, conclusion, linked evidence *versions*, citations; for B1 the understanding sections). Any change recomputes the hash; active sign-offs are invalidated (never deleted) and the paper returns to draft. Uploading a new version of linked evidence also invalidates.
**Consequences.** Reviewers can always prove what they approved; teams must re-sign after edits.

## ADR-010 Platform-wide, immutable Audit Library (2026-11-14)
**Decision.** Library tables have no `firm_id` (shared, Superadmin-curated, read-only to firms). Versions move draft → published → superseded; one published version per standard (partial unique index). A DB trigger blocks any change to paragraphs of non-draft versions. Citations reference paragraph ids, so they keep pointing at the exact text relied on. Each version records its authorised source (title, URL and/or file with SHA-256). Only framework *structure* is seeded; no standard text is generated.
**Consequences.** Firm-specific methodology content will need a separate firm-scoped library extension (future).

## ADR-011 Pluggable malware scanning (2026-11-14)
**Decision.** `MalwareScanner` interface with `ClamdScanner` (production) and `BasicSignatureScanner` (development: EICAR, executable/script signatures). Scans run on the `documents` queue with tenant context restored; downloads require `clean`. Upload validation also checks the content-sniffed MIME type against a per-extension allow-list.

## ADR-012 TOTP MFA without an external IdP (2026-11-14)
**Decision.** In-app TOTP (RFC 6238) using `pragmarx/google2fa`, secrets in encrypted casts, last-used time step stored to stop replay, recovery codes stored as SHA-256 hashes. Mandatory roles configurable (`audira.mfa.required_for`). SSO/SAML can be added later for larger firms.

## ADR-013 Two-step trial balance import with immutable lines (2026-11-15)
**Decision.** Upload stores the file as a Document (hashed, scanned evidence) and parses rows into a pending import; the user confirms the header row and columns against a preview; activation validates strictly and writes `tb_lines`, which a trigger makes immutable. Active imports must balance (CHECK). A new import for the same period supersedes the previous one rather than editing it.
**Consequences.** Every figure in a lead schedule traces to an immutable line and to the exact source file. Corrections are re-imports or adjustments, never edits.

## ADR-014 Mapping keyed by account code; suggestions never auto-applied (2026-11-15)
**Decision.** `account_mappings` is unique per (engagement, account_code) so it survives re-imports and covers both years. Suggestions come from (1) the same client's earlier engagements and (2) ordered name rules in `config/audira.php`; a rule may target `null` to force manual judgement (e.g. borrowings current/non-current, overdrafts). Rules targeting an FSLI that the engagement does not use fall through to the next rule. Each mapping records its source (manual / carried_forward / rule / ai).

## ADR-015 Adjustment journal with segregation and frozen posting (2026-11-15)
**Decision.** Adjustments are balanced journals validated against the current TB. Posting requires engagement partner or manager and a person other than the preparer (service check + DB CHECK `posted_by <> prepared_by`). A trigger blocks changes to lines once posted or voided. Voiding is recorded, not deleted. Posting/voiding invalidates affected lead-schedule sign-offs.
**Consequences.** Corrected/uncorrected classification and approval against materiality are added in Phase 8 on top of this journal.

## ADR-016 FSLI templates are presentation captions, not standards content (2026-11-15)
**Decision.** Platform templates contain generic financial statement captions used to group a TB (corporate; strata for JMB/MC, selected automatically by client entity type). They do not encode disclosure requirements; those come from the Audit Library. Engagements copy a template and adapt it.

## ADR-017 Provider-independent LLM gateway (2026-11-16)
**Context.** CLAUDE.md requires that AUDIRA is not tied to one AI vendor; firms may need a regional or self-hosted model.
**Decision.** Agents and the runtime use a canonical message format (text, tool_use, tool_result blocks) and the `LlmClient` interface. Drivers translate to the Anthropic Messages API and the OpenAI Chat Completions API (which also covers Azure OpenAI and local OpenAI-compatible servers via `OPENAI_BASE_URL`). Models are chosen by task tier through `config/ai.php`, never named in agent code. Transport failures (connection, 429, 529, 5xx) are retryable; other 4xx are not.
**Consequences.** Adding a provider is one driver class. Provider-specific features (prompt caching, batch) are opt-in per driver later.

## ADR-018 Agent tools = agent allow-list ∩ requesting user's permissions (2026-11-16)
**Context.** An agent must never be able to do more than the person who started it, nor more than its role.
**Decision.** Each agent declares a tool allow-list in `config/ai.php`; each tool declares the permission it needs and runs only inside the run's engagement via `ToolContext`. At run time the model is offered, and may call, only tools in the intersection. Calls outside it are refused and logged as `tool_denied`. Phase 4 tools are read-only; agents produce suggestions that a person accepts through the normal services.
**Consequences.** Write-capable tools (drafting risks, procedures) in later phases must write *drafts* only and keep human approval gates (CLAUDE.md §9).

## ADR-019 Guardrails: schema, business rules and unverified figures (2026-11-16)
**Context.** Model output can be malformed, refer to non-existent accounts, or invent numbers.
**Decision.** Final output must match the task's JSON schema (one repair turn). Each agent's `guard()` removes items that fail business rules (unknown accounts/FSLIs, duplicates) and records violations. For narrative output, amounts ≥ 1,000 that do not appear in the data the tools returned are flagged as `unverified_figures` and shown to the user. Any violation ends the run as `needs_review`, never silently `completed`.
**Consequences.** Users see exactly what was removed or doubted. The citation guardrail (paragraph ids must resolve in the Audit Library) joins this layer with RAG in Phase 5.

## ADR-020 AI usage metered in credits against a plan allowance (2026-11-16)
**Decision.** Every model call writes an `ai_usage` row (tokens, provider cost in micros when prices are configured, credits = ceil(tokens / 1,000)). A firm's monthly allowance is `plans.ai_credits_per_user` × billable users; runs are refused once used. Both the allowance and prices are configuration, not code.
**Consequences.** Pricing can be tuned without deployment; cost visibility per firm is available to the Superadmin. Placeholder allowances must be set before launch.

## ADR-021 AI is off until a firm admin opts in (2026-11-16)
**Context.** Sending client data to an external provider is a confidentiality and PDPA decision for each firm.
**Decision.** `firms.ai_enabled` defaults to false. Enabling requires a firm admin and an explicit acknowledgement of what data is sent; who enabled it and when is stored and logged. Firms can disable individual agents. AI is also unavailable while no provider key is configured on the installation.
**Consequences.** No AI call is ever made for a firm that has not opted in; tests assert this.

## ADR-022 Materiality is computed, versioned and frozen (2026-11-17)
**Context.** CLAUDE.md §6: four materiality levels, rationale and approval history; never imply a fixed ISA percentage.
**Decision.** `materiality_assessments` holds one row per version (planning → revised → final). The benchmark amount is taken from AUDIRA's adjusted TB (or a manual basis with a mandatory explanation); overall, performance and clearly trivial amounts are computed on the server from the percentages the team enters. A DB trigger freezes approved versions (only approved → superseded is allowed); approving a revision supersedes the previous version in the same transaction. Partial unique indexes allow one approved and one open version per engagement. Percentages carry no defaults and the UI states that no standard prescribes them.
**Consequences.** Later modules read `Engagement::currentMateriality()`. Firm methodology ranges can be added as guidance without changing the model.

## ADR-023 Engagement-scoped approvals belong to the engagement partner (2026-11-17)
**Context.** Firm-role checks alone let any firm partner approve another partner's engagement.
**Decision.** `approvals.engagement_id` is set from `Approvable::approvalEngagement()`. Deciders need the firm role in `approval_deciders` **and** an engagement role in `approval_engagement_roles` (engagement partner for materiality, significant risks, conclusions and reporting; partner or manager for sampling plans). A request is refused if no one other than the requester could decide it, so the engagement partner cannot submit what only they may approve. Approval lists show engagement approvals only to people who can see the engagement.
**Consequences.** A sole practitioner needs a second team member to prepare and submit items for approval, which matches the segregation intent.

## ADR-024 Risk register: significance is approved, risks are withdrawn not deleted (2026-11-17)
**Decision.** Risks record level, FSLIs (pivot), assertions and inherent risk factors (validated enum arrays), likelihood × magnitude (a spectrum display, not a formula for significance), fraud and controls reliance. `is_significant` is the team's judgement; `significance_status` tracks partner approval and resets when substantive fields change. The two presumed fraud risks are keyed by `presumed_key`: management override is always significant and cannot be withdrawn; the revenue presumption can only be rebutted with recorded reasons. Withdrawn risks remain in the file with the reason.

## ADR-025 Planning working papers attest to planning data (2026-11-17)
**Decision.** Following B1 and lead-schedule papers, A1 / C1 / D1 are system working papers whose content hash includes the strategy, the risk register and the materiality in force. Sign-off gates replace the free-text conclusion requirement: D1 needs approved materiality; C1 needs both presumed risks addressed and all significant risks approved; A1 needs its required sections, approved materiality and approved significant risks. Any later change invalidates sign-offs.

## ADR-026 Agents cite only what they retrieved from the published library (2026-11-17)
**Context.** CLAUDE.md §4 forbids fabricated standards or paragraphs; the library may hold licensed text only.
**Decision.** `LibraryRetriever` searches published versions only (PostgreSQL full-text; plus pgvector similarity merged by reciprocal rank fusion when `AI_RAG_DRIVER=hybrid`, embeddings from an OpenAI-compatible endpoint). The `library_search` tool returns paragraph ids, citations and short excerpts. Guardrails keep a citation only if that paragraph was returned by `library_search` in the same run and is still published; anything else is removed and reported. Wording that presents a percentage as required by a standard is flagged.
**Consequences.** With an empty library, agents cite nothing rather than inventing references. The vector path requires pgvector in production and is untested in the build sandbox.

## ADR-027 Sampling: documented formulas, firm factors as inputs (2026-11-18)
**Context.** CLAUDE.md §7 forbids arbitrary sample sizes and representing judgemental selection as statistical.
**Decision.** Statistical sizes and bounds use the Poisson distribution, computed numerically (`SamplingMath::poissonUpperLimit`) rather than copied from a published table: MUS n = ⌈V·UL(0,c)/(TM − EM·EF)⌉, attributes = smallest n with UL(k,c)/n ≤ TDR, MUS evaluation by the cell method, attributes by the upper deviation limit. Factors that belong to a firm's methodology — the MUS expansion factor and the non-statistical assurance factor — have no defaults and must be entered with a rationale. Judgemental and haphazard selection are non-statistical by database constraint, have no computed size and require a reason per item; their projections are labelled indicative. Tolerable misstatement cannot exceed performance (or specific) materiality.
**Consequences.** Sizes may differ slightly from binomial or AICPA tables; the working paper shows the formula and every input.

## ADR-028 Approved sampling plans are frozen; selection is reproducible (2026-11-18)
**Decision.** Approval (engagement partner or manager, never the requester) must precede selection. Triggers freeze the population and parameters of approved plans and make the selection log immutable except for results. Selection uses PHP MT19937 seeded with a stored random seed over the population in upload order; the population SHA-256 and algorithm name are stored, and the plan page re-performs the selection to prove it.
**Consequences.** Changing a population after approval requires a new plan; reviewers and inspectors can re-perform any statistical selection.

## ADR-029 Client portal on the same tenant, separate surface (2026-11-19)
**Decision.** Client contacts are `users` of type `client` with `firm_id` and `client_id` (DB check), so RLS and tenant context work unchanged. They use `/portal` routes behind `portal` middleware; every firm route redirects them to the portal. Portal controllers re-check that the engagement belongs to the user's client (404 otherwise) and expose only PBC items in requested/submitted/accepted/returned states and sent messages. Portal users are free and excluded from billing.
**Consequences.** One identity system; a client contact belongs to one client of one firm (email unique), like staff (ADR-003).

## ADR-030 Nothing reaches the client without a person (2026-11-19)
**Context.** ROADMAP risk #2: AI client communication vs untrusted client messages.
**Decision.** Outbound messages are drafts until sent by a team member with `engagement.message_client`; a DB check requires `sent_by`/`sent_at` for sent messages and a trigger freezes them. AI drafts are stored with `author_type = agent` and `ai_run_id` and still need a person to send. Inbound client messages and document text are passed to agents only as labelled untrusted data. Email notifications contain a subject line and a portal link, never audit content.

## ADR-031 Material adjustments: firm-chosen basis, approval bound to the lines (2026-11-20)
**Context.** CLAUDE.md §9 requires human approval of material adjustments but does not define "material".
**Decision.** An adjustment is material when its total debits are at or above `audira.material_adjustment.basis` of the approved materiality (`om`, `pm` default, `ct`, or `all`); with no approved materiality, every adjustment is material. Approval (engagement partner, never the requester) snapshots a SHA-256 of the journal lines; posting requires an approved approval whose hash matches the current lines, and editing after a decision clears it. Preparer ≠ poster still applies.
**Consequences.** The basis is a firm-methodology choice (open decision for firms); a conservative default avoids silently posting large journals before planning is approved.

## ADR-032 Summary of audit differences informs, never concludes (2026-11-20)
**Decision.** Misstatement amounts are stored as recorded − audited per FSLI. Items below the approved clearly-trivial amount are listed but not accumulated. Uncorrected misstatements are compared individually, per FSLI (specific materiality where set, else overall) and in aggregate (both net and gross, the larger used for flags) with overall and performance materiality. The page states facts and flags; materiality of uncorrected misstatements and the effect on the opinion remain the engagement partner's judgement (completion approvals, Phase 9). Profit-effect netting across FSLIs is not attempted because direction on profit depends on the double entry, which findings do not record.
**Consequences.** Gross aggregation is conservative; the team can explain offsets in the completion memo.

## ADR-033 AI-drafted test results must quote verifiable evidence (2026-11-20)
**Decision.** The Testing & Evidence agent only sees untested items of the sample the user chose, with excerpts of engagement documents that mention each item reference. The guard keeps a result only if the quote is a verbatim substring of an excerpt offered for that item and (monetary tests) the amount relied on appears in the quote. Accepting records the result with the evidence link, and the accepting auditor becomes `tested_by`. The Findings agent may only use misstatement amounts AUDIRA computed for a sample (exception, factual total, projected remainder); judgemental amounts that cannot be traced are flagged for verification.
**Consequences.** Without extractable text (scanned images, OCR not configured) the agent drafts nothing for that item — a safe failure.

## ADR-034 Completion is staged: conclusions → EQR → completion (2026-11-21)
**Decision.** One `engagement_completions` row per engagement carries the final conclusions (hash-bound `final_conclusions` approval), the EQR record and the `completion` approval. Each stage has explicit gates computed live (and snapshotted into the approval request). The EQR, when an EQR reviewer is assigned, is recorded after the conclusions are approved; changing the conclusions after a decision clears both the approval and the EQR. The checklist locks once conclusions are submitted.
**Consequences.** The completion approval cannot be obtained while any working paper is unreviewed or any review note is open — the file is complete before the opinion is considered.

## ADR-035 The opinion is the partner's; the wording is the firm's (2026-11-21)
**Context.** CLAUDE.md: AI never issues or signs an opinion; never fabricate standards text.
**Decision.** The team records a proposed opinion; the engagement partner decides it through the `audit_opinion` approval and signs through `report_signoff`, both requiring password (and MFA code if enrolled) at the moment of approval (`audira.reauth_approval_types`). AUDIRA ships no auditor's-report wording: firms maintain templates per opinion type with placeholders; without a template a headings-only skeleton containing "[Insert …]" markers is generated, and sign-off is blocked until no placeholders or markers remain. Sign-off stores the body hash and an FS snapshot, freezes the report by trigger and sets the engagement to signed off (locking every module through `EngagementGuard`). No agent has a tool or output field for the opinion; the Completion agent's guard removes opinion wording.
**Consequences.** Firms must load their approved wording before the first sign-off (open item for onboarding).

## ADR-036 Financial statements in Phase 9 are primary statements only (2026-11-21)
**Decision.** AUDIRA presents the SOFP and SOPL from the adjusted TB by FSLI classification with prior year and balance checks; notes, policies, SOCE, cash flows and framework-specific formats (MFRS/MPERS/MPSAS, JMB/MC, cooperatives) remain team-prepared documents linked as evidence. The figures used at sign-off are snapshotted on the report.
**Consequences.** A full FS generator with notes per framework is a future module once firms supply templates.

## ADR-037 Gateway-agnostic invoicing; no tax assumed (2026-11-22)
**Context.** ROADMAP risk #5: gateway and tax treatment not specified.
**Decision.** AUDIRA issues its own invoices (numbering, amounts, frozen by trigger) and treats payment collection as a pluggable `PaymentGateway`. Only the `manual` driver (bank transfer + staff-recorded payment) is implemented; adding a hosted-checkout provider (e.g. an FPX/card provider) means a new driver with verified webhooks — no card data in AUDIRA. Tax is applied only when `AUDIRA_TAX_NAME` and `AUDIRA_TAX_RATE` are set; nothing is assumed about SST or other taxes.
**Consequences.** Billing works end to end today with manual collection; online payment and the tax position remain open decisions for the business.

## ADR-038 Monitoring is rules first, AI second (2026-11-22)
**Decision.** Health alerts come from deterministic rules evaluated daily per firm in tenant context (one open alert per rule, partial unique index), so they are reproducible and free. The Monitoring agent only summarises alerts and progress on request and has no write tools. Notifications go to the engagement partner and manager for new alerts only.
**Consequences.** No credits are consumed by scheduled monitoring; thresholds live in `audira.monitoring`.

## ADR-039 Break-glass support: firm-granted, time-boxed, metadata-only (2026-11-22)
**Context.** ROADMAP risk #7: support needs vs client confidentiality.
**Decision.** Platform staff have no standing access to firm data. A firm admin grants access with a reason for at most 72 hours (DB check), one grant at a time, revocable. The support view is a separate read-only admin page that renders metadata only (users and MFA state, engagement codes/statuses/dates, audit-trail event names) — never documents, working papers, correspondence, client names in engagement lists or figures — and logs every view to the firm's audit trail with a view counter.
**Consequences.** Support for content-level problems happens with the firm on a call; the firm keeps full control and evidence of access.

## ADR-040 Browser hardening baseline (2026-11-22)
**Decision.** All web responses carry a same-origin CSP, `frame-ancestors 'none'`, nosniff, strict referrer and permissions policies, COOP and HSTS on TLS; authenticated responses are `no-store`. Scripts still allow `'unsafe-inline'`/`'unsafe-eval'` because the standard Alpine build evaluates inline expressions.
**Consequences.** Moving to the Alpine CSP build with nonces is the next tightening step (SECURITY.md checklist).

