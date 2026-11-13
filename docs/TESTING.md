# AUDIRA — Testing Strategy

## Principles
- Tests run against **PostgreSQL** (never SQLite) so RLS, CHECK constraints, partial indexes and triggers are exercised.
- The test database user must be the same **non-superuser** role as production (a test asserts this).
- Business rules are tested through HTTP feature tests (routing + middleware + policy + service + DB) wherever possible; pure calculation logic (materiality, sampling, projection) gets unit tests with documented expected values.
- No mock data in place of backend behaviour; fixtures are created through factories or the real services.

## Running
```bash
createdb audira_test           # owned by the app role
vendor/bin/phpunit             # or: composer test
```

## Current suite (186 tests, 1,904 assertions — all passing 2026-11-22)

| Area | File | What it proves |
|---|---|---|
| App-level isolation | `Tenancy/TenantIsolationTest` | foreign URLs 404, foreign IDs rejected, scope fails closed, cross-tenant writes and `firm_id` changes throw |
| DB-level isolation | `Tenancy/RowLevelSecurityTest` | role is not superuser/BYPASSRLS; raw SQL sees only current tenant; foreign inserts rejected; audit trail append-only (trigger + model) |
| Authentication | `Auth/AuthenticationTest` | login, generic errors, rate limiting, deactivated users, firm registration (trial + subscription + admin partner), password rules, suspended firms |
| People | `Users/UserManagementTest` | invitation lifecycle (hashed token, single use, expiry), seat limits, role changes, last-admin protection, cross-firm protection |
| Clients | `Clients/ClientAcceptanceTest` | validation (FYE, duplicate reg. no.), permissions, full acceptance approval with segregation of duties (service + DB constraint), rejection & resubmission, contacts, delete guard |
| Engagements | `Engagements/EngagementTest` | accepted-client gate, code generation, partner/EQR/administrator role rules, membership-based visibility, status transitions, sign-off gate, partner reassignment |
| MFA | `Auth/MfaTest` | enforcement by role, enrolment, encrypted secret, challenge on login, replay rejection, single-use recovery codes, cannot disable when mandatory |
| Documents | `Documents/DocumentTest` | hashing, opaque keys, scan + download gate, EICAR block, disguised/exe rejection, DB immutability, re-versioning invalidates sign-offs, role access, archive guard, RLS |
| Working papers | `WorkingPapers/WorkingPaperTest` | sign-off cycle and segregation, conclusion required, invalidation with history, review-note lifecycle, EQR read-only, locked engagements |
| Entity understanding | `WorkingPapers/EntityUnderstandingTest` | B1 creation, section whitelist, sign-off and invalidation, viewer restrictions, stale-membership regression |
| Audit library | `Library/AuditLibraryTest` | curation, publish rules, supersession, DB-level immutability, CSV all-or-nothing, drafts hidden from firms, search, citation of published only (synthetic content) |
| Amount parsing | `Unit/AmountParserTest` | accounting formats (commas, RM, brackets, trailing minus, CR/DR, floats), rejection of garbage |
| Spreadsheet reading | `Unit/SpreadsheetReaderTest` | XLSX shared strings/numbers/empty rows, CSV delimiter + BOM detection, row limits |
| TB import | `TrialBalance/TrialBalanceImportTest` | column detection, activation, warnings, XLSX single-balance mode, unbalanced/duplicate/invalid rejection, supersession keeps mappings, permissions, tenancy, immutability + RLS, disguised files |
| Mapping & lead schedules | `TrialBalance/MappingAndLeadScheduleTest` | statement figures and checks, opposite balances, carried-forward and rule suggestions require acceptance, foreign FSLI rejection, FSLI management, CSV export safety, rule regex validity, strata template |
| Adjustments | `TrialBalance/AdjustmentTest` | balanced/valid lines, numbering per type, preparer ≠ poster (service + DB), posting flows to lead schedules, frozen lines, void reverses effect, lead-paper sign-off invalidation |
| JSON schema guardrail | `Unit/JsonSchemaTest` | types, required, enums, string length, array size, nested arrays, additionalProperties, JSON extraction from fenced/prose text |
| LLM gateway | `Ai/LlmGatewayTest` | Anthropic and OpenAI request/response mapping incl. tool calls, error classification (429/529/5xx retryable, 4xx not), unconfigured provider |
| Agent runs | `Ai/AgentRunTest` | full tool loop with denied tool, guardrail removal, metering and credits, open suggestions listed and accepted with source `ai`; schema repair; retries and permanent failure; unverified-figure flag; gating (permission, opt-out, disabled agent, budget); duplicate active run; append-only steps; tenant isolation of runs |
| AI settings | `Ai/AiSettingsTest` | firm-admin opt-in requires acknowledgement, agent disable, opt-out, non-admin forbidden |
| Materiality | `Planning/MaterialityTest` | server-computed amounts and benchmarks, validation, engagement-partner approval and segregation (incl. refusal when the partner submits), rejection and resubmission, DB-frozen approved versions, revision superseding, D1 gates and invalidation, approval visibility and tenancy |
| Risk assessment | `Planning/RiskTest` | validation and permissions, references, significant-risk approval and re-approval after substantive change, edit lock while pending, presumed fraud risks (no withdrawal of override, rebuttal of revenue only), C1 gates, coverage check, invalidation |
| Audit strategy | `Planning/StrategyTest` | permissions, date validation, section whitelist, A1 gates, preparer/reviewer sign-off and invalidation on edit |
| Planning agents | `Ai/PlanningAgentsTest` | retrieval of published paragraphs only, materiality figure correction and computed amounts, citation filtering (unretrieved / draft / invented), prescriptive-wording flag, tool allow-list, permission to start, accept → draft + D1 citations; risk agent FSLI/assertion/duplicate guardrails, unverified figures, accept with browser-style string inputs, no duplicates on re-accept |
| Sampling maths | `Unit/SamplingMathTest` | Poisson limits vs known values; MUS, attributes and non-statistical sizes with worked examples; MUS cell evaluation; attributes and ratio evaluation; selector reproducibility, key items, MUS hits, systematic and stratified allocation |
| Audit programme | `Fieldwork/ProgrammeTest` | permissions, assignment to team only, risk tracing, A2 gates (uncovered risks, substantive response to significant risks, unassigned), completion needs conclusion, invalidation |
| Sampling | `Fieldwork/SamplingTest` | MUS end to end (hash, reconciliation, materiality cap, size 36, approval by manager not requester, frozen population, seeded selection + re-performance, immutable selection, results, evaluation numbers); reconciliation notes; bad files; judgemental (forced non-statistical, reasons); non-statistical factor; attributes size; method/type constraint; permissions |
| Fieldwork agents | `Ai/FieldworkAgentsTest` | programme agent risk/FSLI guardrails and sample-size wording flag, accept; sampling agent subject scoping, parameters applied with AUDIRA-computed size, still draft |
| Client portal | `Portal/PortalTest` | invitation and activation, client users redirected from firm routes, other clients' engagements 404, not billed; PBC send/notify, drafts hidden, upload → scanned client document, return with note, accept; message draft → send by a person, frozen, client reply stored as inbound |
| Portal agents | `Ai/PortalAgentsTest` | communication draft guardrails (invented references, audit-judgement wording) saved as draft only; document extraction keeps only values in the text, injection text passed as labelled data, extraction recorded, request not accepted by AI |
| Findings & audit differences | `Fieldwork/FindingsTest` | evidence links (engagement documents only) with selection still frozen; findings from an evaluated sample (factual 300.00, projected 533.33, once only); clearly trivial filter; SAD net/gross and OM/PM flags; uncorrected needs reason, corrected needs posted adjustment; validation per type; significant finding approval by partner only, edit lock while pending, re-approval after change; resolve/withdraw rules; DB checks; material-adjustment threshold, approval bound to lines, edit clears approval; procedure working paper opened once |
| Testing & findings agents | `Ai/TestingAgentsTest` | results kept only with verbatim quotes from documents offered for that item and the amount in the quote; items outside the sample, duplicates and wrong amounts removed; accept records result + evidence + tester, no re-testing; findings agent keeps only AUDIRA-computed amounts, clears unknown references, flags materiality/opinion wording, accept creates AI findings without significance, rerun drops already-recorded sample findings |
| Completion & reporting | `Completion/CompletionTest` | checklist creation and permissions, N/A needs a reason, gates block submission (incl. misstatement awaiting management), partner-only conclusions approval, checklist lock, reviewed papers required, conclusions locked during completion; EQR required when assigned, only after conclusions, only by the EQR, cleared by later changes; report validation (basis for modification, date after period end), skeleton with no standard wording, opinion approval needs password, wrong password refused, firm templates with placeholder validation and permission, sign-off blocked by placeholders, re-authenticated sign-off freezes report (trigger), FS snapshot, engagement signed off and read-only; FS figures |
| Completion agents | `Ai/CompletionAgentsTest` | completion drafts: opinion wording removed, materiality/going-concern conclusions and unverified figures flagged, closed/unknown items removed, accepted drafts stay open; quality review notes: unknown papers and opinion references removed, nothing raised until accepted, raised in the accepting reviewer's name, staff cannot raise |
| Billing & support | `Platform/BillingAndSupportTest` | minimum-seat billing, no tax unless configured, tax maths, numbering, one invoice per period, frozen amounts (trigger), firm sees only its invoices, payment/void by superadmin, billing run issue + roll + past due + restore on payment; support grants only by firm admins, ≤72 h (validation + DB), one at a time, logged views with counter, revoked/expired refused; security headers and no-store |
| Monitoring | `Platform/MonitoringTest` | deadline/overdue-request/no-materiality alerts, notifications to partner and manager only and only for new alerts, escalation, auto-resolution, overview panel; monitoring digest guard (unknown alert kinds, opinion wording) |
| Platform | `Admin/SuperadminTest` | admin area isolation both ways, plan editing with price snapshots, plan change, suspend/restore, billing minimum & free client users, superadmin CLI |

## Checklist for every new module
1. Tenant table added to an RLS migration + an isolation test (foreign ID → 404).
2. Policy tests for each role, including "engagement member vs non-member".
3. Every human-approval gate: requester ≠ approver test and unauthorised-role test.
4. Activity log entry asserted for state changes.
5. For calculations: unit tests with worked examples documented in the test.
6. Browser smoke check of new screens (Playwright script in CI from Phase 2).

AI tests never call a real provider: `TestCase` resets provider keys and base URLs, `phpunit.xml` forces empty keys, `AiTestCase` sets a fake key, calls `Http::preventStrayRequests()` and fakes provider responses with `Http::sequence()`.

Note: `phpunit.xml` sets `AUDIRA_MFA_ENFORCED=false` so unrelated feature tests don't need an MFA step; `MfaTest` re-enables enforcement explicitly.
