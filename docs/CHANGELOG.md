# Changelog

## [1.0.0] — 2026-11-22 — Phase 10 (Billing, monitoring, platform analytics, break-glass support, hardening)

### Added
- Invoicing: monthly per-user invoices (plan snapshot, minimum seats, client users free), sequential numbers under an advisory lock, one invoice per subscription period, tax line only when configured, frozen amounts (DB trigger; void and reissue), payment recording and voiding by platform staff, daily `audira:billing-run` (issue, roll period, past-due after grace, restored on payment). Pluggable `PaymentGateway` with the `manual` (bank transfer) driver. Firm billing page lists invoices; printable invoice view.
- Engagement monitoring: daily `audira:monitor` rules (reporting deadline, overdue client requests, stale approvals, stale review notes, uncorrected misstatements vs materiality, fieldwork without approved materiality), one open alert per rule, auto-resolution, mail to the engagement partner and manager for new alerts only; alerts on the engagement overview. Monitoring agent (`health_digest`) turns alerts into priorities (advisory).
- Superadmin analytics: outstanding/overdue/collected billing, past-due subscriptions, engagement counts by status, reports signed this month, AI runs by agent, active support grants (counts only).
- Break-glass support access: firm admins grant time-boxed (≤72 h, DB check), read-only, metadata-only access with a reason; platform support sees users, engagement codes/statuses and audit-trail events only; every view logged in the firm's audit trail; revoke any time.
- Security headers middleware (CSP, frame denial, nosniff, referrer and permissions policies, COOP, HSTS on TLS, `no-store` for authenticated pages).
- Tables `invoices`, `monitoring_alerts`, `support_access_grants` (RLS) and partial indexes for pending approvals, findings and requests.

## [0.9.0] — 2026-11-21 — Phase 9 (Completion, quality review, financial statements, reporting and sign-off)

### Added
- Completion tab: completion checklist from a firm-extendable template (`audira.completion.items`, ISA-topic prompts, no standard text), final conclusions (misstatements, going concern, overall) with **final conclusions** approval, engagement quality review recorded by the assigned EQR reviewer, **completion** approval with gates (checklist, management responses, significant findings, other pending approvals, all working papers reviewed, review notes cleared, procedures completed, samples evaluated, TB balanced and mapped, EQR).
- Financial statements: statement of financial position and profit or loss from the adjusted TB and FSLI mapping, with prior year, balance checks and print view; snapshotted at sign-off.
- Audit report: proposed opinion (unmodified / qualified / adverse / disclaimer) with basis for modification, going-concern material uncertainty, emphasis of matter and other matter; report text generated from the **firm's** template (headings-only skeleton when none) with placeholders; **audit opinion** and **report sign-off** approvals by the engagement partner with password (and MFA code) re-authentication; sign-off freezes the report (DB trigger) with SHA-256 and FS snapshot and moves the engagement to signed off.
- Firm report templates (`/firm/report-templates`, firm admins and partners) with placeholder validation.
- Completion agent (drafts checklist responses; never completes items, concludes on materiality/going concern or mentions the opinion) and Quality Review agent (drafts review notes raised by a person). Tools `completion_checklist`, `review_status`.
- Tables `completion_items`, `engagement_completions`, `report_templates`, `audit_reports` (RLS, CHECKs).

### Changed
- `ApprovalService::decide` requires re-authentication to approve types in `audira.reauth_approval_types`.
- Figure verification helper moved to the base agent (`knownAmounts`).

## [0.8.0] — 2026-11-20 — Phase 8 (Testing, evidence, findings, misstatements, material adjustments)

### Added
- Evidence links: each sample result can link the engagement document that evidences it; each procedure can open its own fieldwork working paper (F-section) where work is documented, evidence linked and signed off.
- Findings register (`findings` table, RLS): misstatements (factual / judgemental / projected, recorded − audited, correction status with management's reason), control deficiencies (deficiency / significant deficiency; significant ones always communicated), other matters. Significant findings need engagement-partner approval; edits after a decision require a fresh approval.
- "Record as findings" on evaluated monetary samples: factual misstatement from the exceptions, projected remainder from the stored evaluation.
- Summary of audit differences (ISA 450): clearly trivial filter, uncorrected misstatements by line item and class against overall / specific materiality, aggregate (net and gross) against overall and performance materiality.
- Material-adjustment approval: adjustments at or above the configured basis of approved materiality (default performance materiality; every adjustment while materiality is unapproved) need engagement-partner approval bound to a hash of the lines before posting.
- Testing & Evidence agent (drafts results that must quote evidence containing the amount) and Findings agent (amounts must be figures AUDIRA computed). Tools `sample_evidence`, `sample_exceptions`, `findings_list`.
- Permission `engagement.manage_findings`; config `audira.material_adjustment.basis`.

### Changed
- `selection_frozen` trigger allows `evidence_document_id` to change with results.

## [0.7.0] — 2026-11-19 — Phase 7 (Client portal, PBC, client communication, document intelligence)

### Added
- Client portal (`/portal`) with invitations, client-scoped access and uploads against requests.
- Client requests tab: PBC items, sending, review (accept / return / cancel), messages with draft → send.
- Client Communication agent and Document Intelligence agent; `DocumentTextExtractor`.
- `portal_invitations`, `pbc_items`, `pbc_item_documents`, `engagement_messages`, `document_extractions` tables with RLS.

### Changed
- Client users are redirected to the portal from firm routes and after sign-in.
- AI tasks can carry a `subject` (sampling plan or document) validated against the engagement.

## [0.6.0] — 2026-11-18 — Phase 6 (Audit programme and sampling)

### Added
- Fieldwork tab: audit programme (procedures traced to risks, assignment, budgets, execution status and conclusions) and sampling.
- Working paper A2 with risk-coverage sign-off gates.
- Sampling engine: population upload and hashing, completeness reconciliation, documented sample-size methods (MUS, attributes, non-statistical), key items, approval before selection, reproducible seeded selection with re-performance check, results, MUS/ratio/attributes evaluation.
- Audit Programme agent and Sampling agent (parameters only).

### Fixed
- Stratified allocation now caps each stratum at its item count and redistributes the remainder.

## [0.5.0] — 2026-11-17 — Phase 5 (Materiality, risk assessment, audit strategy)

### Added
- Planning tab with Audit strategy, Materiality and Risk assessment.
- Versioned materiality (overall, performance, clearly trivial, specific) computed from the adjusted TB, approved by the engagement partner, frozen once approved.
- Risk register with presumed fraud risks, significant-risk approval, coverage check against performance materiality.
- Overall audit strategy (ISA 300 topics, dates, approach).
- Planning working papers A1 / C1 / D1 with content-hash sign-offs and sign-off gates.
- Materiality agent and Risk Assessment agent; accept flows that create drafts and record verified citations.
- Audit Library retrieval for agents (full-text; pgvector hybrid when configured), `EmbedLibraryVersion` job and `audira:library-embed` command; env `AI_RAG_DRIVER`, `AI_EMBEDDINGS_*`.

### Changed
- Approvals carry `engagement_id`; engagement-scoped approvals require the engagement partner (configurable per type) and are hidden from users outside the engagement.
- AI panels and run pages are generic across agents.

### Fixed
- Approval requests are refused when nobody other than the requester could approve them (an empty-collection check was always truthy).
- Accepting AI-proposed risks ignored browser-submitted selections (string vs integer comparison); regression test posts strings.

## [0.4.0] — 2026-11-16 — Phase 4 (AI platform, Accounting & TB agent)

### Added
- LLM gateway with Anthropic and OpenAI-compatible drivers, tier-based model routing and retry-aware error classes (`config/ai.php`, `AI_PROVIDER`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `OPENAI_BASE_URL`).
- AI orchestrator and agent runtime on the `ai` queue: tool allow-lists intersected with the requesting user's permissions, JSON-schema validated tool inputs and outputs, one repair attempt, business-rule guardrails, step limit.
- Append-only `ai_run_steps` execution log and `ai_usage` metering (RLS); monthly AI credit allowance per plan and billable user.
- Firm AI settings (opt-in with acknowledgement, per-agent disable, monthly usage) and Superadmin AI platform card.
- Accounting & TB agent: FSLI mapping suggestions accepted by a person (mapping source `ai`) and analytical review of the TB with figures checked against AUDIRA data.
- Engagement "AI assistant" tab, run log and run detail with full execution trace; AI panels on the Mapping and Lead schedules screens.

### Fixed
- AI mapping panel showed no open suggestions for unmapped accounts (a null-coalescing check treated `null` as missing); regression test added.
- Test suite now pins AI provider configuration so a developer's shell or `.env` cannot make tests call a real endpoint.

### Notes
- Seeded plan AI credits (50 / 200 / 500 per user) and model prices are placeholders.
- RAG on pgvector moved to Phase 5.

## [0.3.0] — 2026-11-15 — Phase 3 (Trial balance, mapping, FSLIs, lead schedules, adjustments)

### Added
- Trial balance import from CSV/XLSX (dependency-free XLSX reader), automatic column detection, strict validation, current and prior year, supersession, immutable lines.
- FSLI templates (corporate; strata JMB/MC auto-selected) and engagement FSLI management.
- Account mapping with carried-forward and rule-based suggestions that require acceptance.
- Lead schedules and draft statements with integrity checks; lead-schedule working papers that attest to their figures.
- Adjustment journal with segregation of duties (preparer ≠ poster) and frozen posted lines.
- Adjusted TB export (CSV) with formula-injection protection; engagement "Trial balance" tab.

### Fixed
- Mapping rules: word-boundary escapes were written as control characters, so some rules never matched; a regression test now checks every rule.

## [0.2.0] — 2026-11-14 — Phase 2 (Working papers, documents, library, entity understanding, MFA)

### Added
- Two-factor authentication (TOTP + recovery codes), mandatory for partners, firm admins and Superadmin; `mfa` middleware with challenge on each session.
- Secure document management with versioning, SHA-256 integrity, content-type verification, queued malware scanning and audited downloads.
- Working-paper engine: references by section, preparer/reviewer sign-offs bound to content hashes, automatic invalidation, review notes, evidence links and library citations.
- Entity understanding (B1) with 15 structured sections.
- Platform Audit Library with draft → published → superseded versions, immutable published paragraphs, CSV import, search and firm browsing; Superadmin curation screens.
- Engagement tabbed workspace; demo seeder extended.

### Changed
- Engagement permission checks always query current team membership (fixes stale-relation access denial).
- Packages: `pragmarx/google2fa` ^8.0, `bacon/bacon-qr-code` ^3.0.

## [0.1.0] — 2026-11-13 — Phase 0 + Phase 1 (Foundation)

### Added
- Architecture baseline: ARCHITECTURE, DATABASE (ERD), AI_AGENTS, AUDIT_WORKFLOW, SECURITY, ROADMAP, DECISIONS, TESTING.
- Multi-tenancy: `TenantContext`, fail-closed `FirmScope`, `BelongsToFirm`, PostgreSQL RLS on all tenant tables and `firms`.
- Authentication: sign-in (rate limited), sign-out, firm self-registration on a trial, invitation acceptance, password change; deactivated users and suspended firms blocked.
- Firm profile (SSM/LLP no., MIA AF no., default framework).
- People: invitations with hashed single-use tokens and seat limits, role changes, firm-admin flag, deactivate/reactivate, last-admin protection.
- Clients: CRUD with Malaysian entity types (incl. JMB/MC, cooperatives) and frameworks (MFRS/MPERS/MPSAS), contacts, acceptance & continuance assessment routed to partner approval.
- Engagements: creation for accepted clients only, auto codes, team roles (engagement partner, EQR reviewer, manager, senior, staff) with eligibility rules, membership-based visibility, lifecycle with sign-off gate, partner reassignment.
- Approvals engine with segregation of duties (service + DB constraint), history, withdrawal.
- Append-only audit trail (DB trigger) with firm activity screen.
- Plans & subscriptions (RM15/20/30 per user, min 3 paid users — all editable), billing estimate, Superadmin overview, plans editor, firm administration (suspend/restore/change plan), `audira:create-superadmin` command.
- Design system (navy/white/grey/teal) on Tailwind 3.4 + Alpine 3.15; demo seeder.
- 52 feature tests on PostgreSQL.
