# AUDIRA — Security & Multi-Tenancy Architecture

## 1. Threat model (top risks)

| Threat | Control |
|---|---|
| Cross-tenant data leak (bug in a query) | App-level `FirmScope` **and** PostgreSQL RLS (defence in depth) |
| Staff seeing engagements they aren't on | Engagement-membership policies; partners/admins see firm-wide |
| Privilege escalation via role edits | Only firm admins change roles; cannot demote the last admin; all changes logged |
| AI agent overreach / prompt injection from client docs | Tool allow-lists, scoped runs, no approve permission, untrusted-content wrapping (AI_AGENTS.md) |
| Self-approval | Approval service rejects requester = approver; DB CHECK constraint backs it up |
| Credential stuffing | Login rate-limited per email+IP; generic error messages; bcrypt/argon2id hashes |
| Tampering with audit trail | `activity_logs` append-only via DB trigger; evidence files SHA-256 hashed |
| Malicious uploads | Allow-listed types, size limits, MIME sniffing, AV scan, never executed or served inline |
| Superadmin snooping on client data | Superadmin has **no** access to tenant audit data; support access requires firm-granted, time-boxed, logged break-glass (Phase 10) |

## 2. Multi-tenancy — three layers

### Layer 1: Application (`app/Support/Tenancy`)
- `TenantContext` (singleton) holds the current `firm_id`.
- `BelongsToFirm` trait: adds `FirmScope` global scope (`where firm_id = ?`) and sets `firm_id`
  on create; throws `TenantViolation` if a model is saved with a different firm.
- If no tenant is set and the code is not in explicit system mode, scoped queries return
  **nothing** (fail closed), never everything.

### Layer 2: Database (PostgreSQL RLS)
- App connects as role `audira` — `NOSUPERUSER NOBYPASSRLS`, owner of the schema.
- All tenant tables: `ENABLE` + `FORCE ROW LEVEL SECURITY` with policy on `app.firm_id`.
- `SetTenantContext` middleware runs `select set_config('app.firm_id', :id, false)` per request,
  reset on terminate. Queue jobs restore it via job middleware.
- System mode (`TenantContext::runAsSystem()`) sets `app.bypass_rls = on` for the duration of a
  closure only — used by migrations, seeders, registration (creating a new firm) and Superadmin
  platform pages. Every system-mode entry point is listed in this document:
  1. Firm self-registration (creates firm + owner).
  2. Superadmin firm list / suspend / plan assignment (firm-level metadata only, no audit data).
  3. Invitation acceptance (lookup by token before the user has a tenant).
  4. Database seeders & migrations.
  5. Queued jobs restore their firm with `runForFirm` (not system mode), e.g. `ScanDocumentVersion`.
  6. Billing (`InvoiceService`: issuing, payments, daily billing run) and Superadmin invoice pages (Phase 10).
  7. `audira:monitor` lists active firms in system mode, then evaluates each firm inside `runForFirm` (Phase 10).
  8. The break-glass support view reads one firm's metadata inside `runForFirm` only while that firm's grant is active (Phase 10, ADR-039).

### Layer 3: Authorisation
- Firm roles: `firm_admin`, `partner`, `manager`, `senior`, `associate`. Platform: `superadmin`.
  Client portal: `client` (free, scoped to one client).
- Permission matrix in `config/audira.php` (`permissions` → roles). Policies consult it via
  `App\Support\Authorization\Permissions`.
- Engagement access: firm_admin & partners → all firm engagements; others → only where they are
  `engagement_members`. Engagement roles gate engagement-level actions.

## 3. Authentication
- Session auth (web) with regenerated session IDs on login, `HttpOnly`, `Secure`, `SameSite=Lax` cookies.
- Login throttled: 5 attempts/min per email+IP (`RateLimiter`), lockout message is generic.
- Passwords: min 12 chars, mixed case, numbers, symbols (`Password::defaults()`).
- Deactivated users and users of suspended firms are logged out by middleware.
- **MFA (Phase 2):** TOTP mandatory for partners, firm admins and Superadmin (others may opt in). Enforced by `mfa` middleware on all firm and admin routes; account pages require the challenge too, so an unverified session cannot disable MFA. Codes are replay-protected; 8 single-use recovery codes (hashed). Challenge throttled 5/min.
- Re-authentication (password + MFA code) to approve the audit opinion and report sign-off (Phase 9, below). Planned: SSO for larger firms.
- API (Phase 3+): Laravel Sanctum tokens scoped by abilities, rate limited per token.

## 3a. Documents & evidence (Phase 2)
- Private disk only; object keys are opaque UUIDs (never the client filename); downloads stream through the app after a policy check with `nosniff`, sandboxing CSP and `no-store`.
- Validation: extension allow-list **and** content-sniffed MIME must match that extension (`ContentMatchesExtension`); max 50 MB; uploads rate-limited.
- Malware scan before availability (`clamd` in production). Infected/failed files are never served.
- Integrity: SHA-256 recorded at upload and re-verified by the scanner; `document_versions` file identity is immutable and rows cannot be deleted (DB trigger). Documents are archived, never deleted.
- Library tables are platform-wide (no RLS by design: public reference material); published paragraphs immutable via trigger.

## 3b. Trial balance files (Phase 3)
- Spreadsheet files are untrusted: extension + content-sniffed type check; XLSX read with ZipArchive/SimpleXML only (no macros or formula evaluation, cached values only), `LIBXML_NONET`, DOCTYPE rejected, uncompressed-size and row limits (zip-bomb guard).
- CSV export prefixes cells beginning with `= + - @` to prevent spreadsheet formula injection.
- Imported lines and posted adjustment lines are immutable (DB triggers); adjustments enforce preparer ≠ poster at DB level.

## 3c. AI platform (Phase 4)
- **Off by default.** No data leaves AUDIRA for an LLM unless the installation has a provider key *and* a firm admin has opted in with an acknowledgement (who/when stored and logged). Firms can disable agents.
- **Scope.** A run belongs to one firm and one engagement. The worker executes inside `runForFirm()` so RLS applies; tools receive only the run's engagement through `ToolContext` and cannot take an engagement or firm id as input.
- **Least privilege.** Tools offered to the model = agent allow-list ∩ permissions of the user who started the run. Calls outside it are refused and logged (`tool_denied`). Phase 4 tools are read-only; agents cannot approve, post, sign off or map — suggestions are applied by a person through the normal services, which re-validate them.
- **Prompt injection.** System prompts state that tool results and engagement content are data, not instructions. Tool inputs are schema-validated; outputs are schema-validated and filtered by business-rule guardrails; numbers not found in AUDIRA data are flagged to the user. The model never receives credentials, other engagements' data or the firm's user list.
- **Bounded execution.** Max 8 model turns per run, capped tool-result size, retries only for transport errors, one active run per engagement/task, `throttle:ai` (10 starts/min per user), monthly credit budget.
- **Audit trail.** Every prompt summary, model response, tool call/result/denial, retry and guardrail outcome is stored in append-only `ai_run_steps` (DB trigger); usage in append-only `ai_usage`; run requests and outcomes in `activity_logs` with `actor_type = agent`.
- **Secrets.** Provider keys only in environment variables (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`); never stored in the database or logs. Tests pin provider config and use `Http::preventStrayRequests()`.

## 3d. Planning and approvals (Phase 5)
- Engagement-scoped approvals carry `engagement_id`; listing and the sidebar count only show approvals on engagements the user can see, and deciding requires the configured engagement role (ADR-023).
- Materiality and specific materiality of approved versions are frozen by DB triggers; risks are withdrawn, never deleted.
- Agents may cite only published library paragraphs they retrieved in the same run (ADR-026). Entity-understanding text passed to agents is labelled `untrusted_text`.
- Embedding requests send only the agent's search query or published library text to the embeddings provider — never engagement data.

## 3e. Client portal (Phase 7)
- Client users (type `client`) can only use `/portal`; firm routes redirect them. Portal controllers check engagement ownership by `client_id` (404) and show only requested/submitted/accepted/returned items and sent messages.
- Portal uploads go through the same validation, hashing, malware scan and versioning as staff uploads.
- Outbound messages need a person to send them (DB check + trigger); notifications contain no audit content (ADR-030).
- Client messages and document text are untrusted: shown escaped, passed to agents only as labelled data, never interpreted.

## 4. Data protection
- TLS everywhere; HSTS.
- Encryption at rest: managed DB/storage encryption; sensitive columns (e.g. tax numbers, bank
  details) via Laravel encrypted casts with key rotation (`APP_PREVIOUS_KEYS`).
- PDPA 2010 (Malaysia): data processing agreement with firms, data residency option in Malaysia
  (e.g. AWS ap-southeast-5), deletion/retention workflow. Legal review required before launch.
- LLM providers: zero-retention / no-training contractual terms required; PII redaction in logs.

## 5. Logging & monitoring
- `activity_logs`: who, what, when, IP, user agent, before/after diff for key fields.
- Security events (failed logins, role changes, approvals, suspensions) also shipped to central log store.
- Alerting on unusual download volume per user, repeated authorisation failures.

## 6. Input handling
- All input through FormRequests; enums validated with `Rule::enum`; IDs resolved via scoped
  route-model binding so a foreign `id` 404s rather than 403s (no existence oracle).
- Blade escapes output by default; `{!! !!}` is banned outside vetted components.
- CSRF on all web forms; CSP headers (Phase 2).

## 7. Backup & recovery
- PITR on PostgreSQL; object storage versioning + lifecycle; quarterly restore drill; RPO ≤ 15 min, RTO ≤ 4 h targets.

## Re-authentication for the opinion and report sign-off (Phase 9)
Approving an `audit_opinion` or `report_signoff` request requires the engagement partner to re-enter their password, and their TOTP code when MFA is enrolled, at the moment of approval (`audira.reauth_approval_types`). The check is enforced in `ApprovalService::decide` (approve requires a re-authenticated caller) and performed in `ApprovalController`; failed attempts are written to the activity log. Signed reports are immutable at database level.

## Phase 10 hardening
- **Headers** (`SecurityHeaders` middleware): CSP `default-src 'self'` with `frame-ancestors 'none'`, `object-src 'none'`, `form-action 'self'`, `base-uri 'self'`; `X-Frame-Options: DENY`; `X-Content-Type-Options: nosniff`; `Referrer-Policy: strict-origin-when-cross-origin`; restrictive `Permissions-Policy`; `Cross-Origin-Opener-Policy: same-origin`; HSTS on HTTPS; `Cache-Control: no-store, private` for authenticated responses.
- **Break-glass support access**: see ADR-039 — firm-granted, ≤72 h, metadata-only, every view logged to the firm's audit trail.
- **Billing integrity**: invoice amounts frozen by trigger; paid/void invoices immutable; payments recorded only by platform staff and logged to the firm's trail.

### Pre-production checklist (to complete before go-live)
1. External penetration test (OWASP ASVS L2) against staging, including tenant-isolation attempts across firms and the client portal.
2. Switch to the Alpine.js CSP build and nonce-based `script-src` (remove `'unsafe-eval'`/`'unsafe-inline'`).
3. `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `APP_DEBUG=false`, trusted proxies configured; TLS only.
4. ClamAV (`AUDIRA_SCANNER=clamd`) and S3-compatible encrypted document storage with versioning.
5. Database: managed PostgreSQL with pgvector, encrypted at rest, PITR backups, app role without superuser/BYPASSRLS (verified by `RowLevelSecurityTest`).
6. AI provider data-processing agreement and region; keys in a secrets manager.
7. Load test on large TBs (10k+ accounts) and populations (100k+ items); review slow-query log.
8. Retention and archiving period confirmed (ROADMAP risk #6) and implemented as a scheduled job.
9. Incident response runbook and on-call contacts.

