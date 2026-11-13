# AUDIRA — Audit Workflow

The workflow follows the external financial-statement audit lifecycle. ISA numbers are given only
as *topic pointers*; authoritative text and paragraph references must come from the versioned
Audit Library (CLAUDE.md §4). Nothing here states a fixed percentage or sample size as an ISA
requirement.

Legend: 🤖 AI may draft/recommend · 👤 human performs · ✅ human approval gate (Approval service)

## Stage 0 — Firm setup (Phase 1)
1. Firm registers → trial subscription on a plan (min. paid users enforced at billing).
2. Firm admin invites staff, assigns firm roles; configures default reporting framework.

## Stage 1 — Client acceptance & continuance (Phase 1) — *ISA 220 / ISQM 1 topics*
1. 👤 Create client (entity type, framework: MFRS/MPERS/MPSAS/other, FYE, SSM no.).
2. 👤 Complete acceptance assessment: integrity/risk rating, independence confirmation, competence & resources notes.
3. ✅ **Client acceptance approval** by a partner (requester ≠ approver). Rejected → client `declined`.
4. Only `accepted` clients can have engagements.

## Stage 2 — Engagement setup (Phase 1) — *ISA 210, ISA 220*
1. 👤 Create engagement (period, type, framework, deadline).
2. 👤 Assign team: one engagement partner (mandatory), optional EQR reviewer, manager, seniors, staff.
3. Engagement status: `planning`.

## Stage 3 — Understanding the entity (Phase 2) — *ISA 315 (Revised 2019)*
1. 🤖 Client Communication agent drafts information requests; 👤 approve & send.
2. 👤 Document entity & environment, applicable framework, internal control components; 🤖 summarise uploaded documents with citations to source documents.

## Stage 4 — Trial balance, mapping, FSLI (Phase 3)
1. 👤 Import TB (CSV/XLSX) → validation: debits = credits, period, duplicate accounts, prior-year comparatives.
2. 🤖 Suggest account → FSLI mappings; 👤 accept/override; mapping templates reused across clients.
3. Lead schedules generated per FSLI.

## Stage 5 — Materiality (Phase 5) — *ISA 320, ISA 450*
1. 🤖 Recommend benchmark & percentage with rationale and alternatives.
2. 👤 Set overall, performance, specific (per FSLI/class) materiality and clearly-trivial threshold; record assumptions & rationale.
3. ✅ **Materiality approval** (engagement partner). Versioned — any revision creates a new version requiring re-approval and flags dependent sampling plans & evaluations for re-assessment.

*Implemented (Phase 5):* Planning › Materiality. Benchmarks come from the adjusted TB; AUDIRA computes the amounts; the engagement partner approves (submitted by another team member); approved versions are frozen and revised by new versions; working paper D1 attests to the version in force and needs it approved before preparer sign-off. The UI flags when the TB benchmark has moved since approval.

## Stage 6 — Risk assessment & strategy (Phase 5) — *ISA 315, ISA 240, ISA 330, ISA 300*
1. 🤖 Draft risks of material misstatement by FSLI and assertion; fraud-risk considerations.
2. 👤 Assess inherent risk factors, control risk; designate significant risks.
3. ✅ **Significant risk approval**.
4. 👤 Audit strategy & plan; linked to materiality and risks.

*Implemented (Phase 5):* Planning › Risk assessment and Planning › Audit strategy. The risk register records level, FSLIs, assertions, inherent risk factors, likelihood × magnitude, fraud and controls reliance; the two presumed fraud risks are added with one click (management override cannot be withdrawn; revenue recognition may only be rebutted with reasons); significant risks need engagement-partner approval and re-approval after substantive change; a coverage check lists FSLIs at or above performance materiality with no linked risk. C1 cannot be signed until both presumptions are addressed and every significant risk is approved; A1 (strategy) needs its required sections, approved materiality and approved significant risks.

## Stage 7 — Audit programme (Phase 6) — *ISA 330*
1. 🤖 Generate procedures responsive to each assessed risk (traceability risk → procedure).
2. 👤 Edit, assign to staff, set budgets.

## Stage 8 — Sampling (Phase 6) — *ISA 530, ISA 500*
1. 👤 Define population; system **validates completeness** and **reconciles** population total to TB/sub-ledger.
2. System identifies individually significant items (above a threshold the auditor sets, e.g. linked to performance materiality) for 100% testing.
3. 👤 Choose method: statistical (MUS, statistical attribute) or non-statistical (random, systematic, haphazard, judgemental, stratified). Judgemental selection is always labelled non-statistical.
4. Sample size computed by a **documented methodology** (formula, inputs and confidence factors stored); no arbitrary sizes.
5. Selection is reproducible: PRNG algorithm + seed + population hash + selection log stored.
6. ✅ **Sampling plan approval** before selection is finalised.
7. Results recorded per item; misstatements projected (MUS / ratio / difference as appropriate); system flags when projected + known misstatement approaches tolerable misstatement → 👤 decide additional procedures.

*Implemented (Phase 6):* Fieldwork › Audit programme and Fieldwork › Sampling, as described in stages 7–8, with A2 sign-off gates, documented formulas (ADR-027) and frozen, reproducible selections (ADR-028).

## Stage 9 — PBC & documents (Phase 7)
1. 👤/🤖 PBC list generated from programme; client portal users upload.
2. Uploads scanned, hashed, versioned; 🤖 Document Intelligence classifies & extracts; matches to PBC/sample items.

*Implemented (Phase 7):* Client requests tab and the client portal; AI drafts and extractions always need a person (ADR-030).

## Stage 10 — Testing, evidence, findings, adjustments (Phase 8) — *ISA 500, ISA 450*
1. Each procedure opens a fieldwork working paper (F1, F2…) where work performed and conclusions are documented, evidence documents linked and preparer/reviewer sign-offs recorded. *(implemented)*
2. Sample results record the evidence document per item. 🤖 The Testing & Evidence agent drafts results that quote the evidence; 👤 the tester accepts each one and becomes tester of record. *(implemented)*
3. Evaluated monetary samples record a **factual** finding (exceptions) and a **projected** finding (remainder of the projection). Other misstatements (incl. **judgemental**), control deficiencies (deficiency / significant deficiency) and other matters are recorded manually or 🤖 drafted by the Findings agent. ✅ **Significant findings approval** (engagement partner). *(implemented)*
4. Management's response per misstatement: corrected (linked to a posted adjustment) or uncorrected (reason required). The **Summary of Audit Differences** lists accumulated misstatements (above clearly trivial) by FSLI and class against overall/specific and performance materiality. *(implemented)*
5. ✅ **Material adjustments approval** (engagement partner; basis configurable, default performance materiality) before posting; posted adjustments flow to the adjusted TB and lead schedules. *(implemented)*

## Stage 11 — Completion & quality review (Phase 9) — *ISA 560, 570, 580, 450, 220, ISQM 2*
1. Completion checklist (12 ISA-topic prompts: subsequent events, going concern, representations, misstatements, communications, related parties, laws and regulations, litigation, estimates, overall review, disclosures, independence). 🤖 Completion agent drafts responses; 👤 the team marks each done or not applicable with a response. *(implemented)*
2. Final conclusions (misstatements, going concern, overall) → ✅ **final audit conclusions** (engagement partner; gates: checklist answered, management responses recorded, significant findings approved, no other pending approvals). *(implemented)*
3. Engagement quality review by the assigned EQR reviewer, after conclusions are approved. 🤖 Quality Review agent drafts review notes; 👤 a reviewer raises them. *(implemented)*
4. ✅ **Completion approval** (gates: conclusions approved, all working papers reviewed, review notes cleared, procedures completed, samples evaluated, TB balanced and mapped, EQR done if assigned). *(implemented)*

## Stage 12 — FS preparation & reporting (Phase 9) — *ISA 700 series; Companies Act 2016*
1. Primary statements (SOFP, SOPL) from the adjusted TB and FSLI mapping, with prior year and checks; notes and framework-specific formats prepared by the team. *(implemented — ADR-036)*
2. Proposed opinion and any modification recorded by the manager; ✅ **audit opinion** decided by the engagement partner with re-authentication. AI has no part in the opinion. *(implemented)*
3. Report text generated from the firm's approved template (placeholders filled; no standard wording shipped); ✅ **audit report sign-off** by the engagement partner with re-authentication, after the EQR date. The report and an FS snapshot are frozen and hashed; engagement → `signed_off` and read-only. *(implemented)*

## Engagement status machine (Phase 1 implemented)

```
planning → fieldwork → completion → reporting → signed_off → archived
    ▲          │           │            │
    └──────────┴───────────┴────────────┘  (step back allowed before signed_off, logged)
```
`signed_off` can only be reached via the report sign-off approval (Phase 9); in Phase 1 the
transition is blocked with an explanatory message.
