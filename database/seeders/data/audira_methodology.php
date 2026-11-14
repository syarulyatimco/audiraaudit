<?php

/*
| AUDIRA Audit Methodology — platform template (version 1).
|
| Original AUDIRA wording. References name the ISA that the step responds to; they do not
| reproduce or paraphrase standards text, and paragraph numbers are deliberately left out so the
| firm's technical reviewer can add the paragraphs from the firm's licensed copy of the standards.
| Firms copy this template and adapt it; it is a starting point, not a substitute for judgement.
|
| Assertion codes: E existence · O occurrence · C completeness · A accuracy · CO cut-off ·
| CL classification · R rights and obligations · V valuation and allocation · P presentation.
|
| Each programme: [ref_prefix, stage, scope, codes|classification, title, objective, steps[]]
| Each step:      [procedure, assertions, isa_refs, guidance]
*/

return [
    // ───────────────────────────── PLANNING (whole engagement) ─────────────────────────────
    ['A1', 'planning', 'engagement', null, 'Engagement acceptance and terms', 'Confirm the firm may accept or continue the engagement and that the terms are agreed in writing.', [
        ['Confirm the client acceptance or continuance assessment is approved in AUDIRA and note any conditions the partner attached.', null, 'ISA 220; ISQM 1', 'Link the approved acceptance record in the WP ref column.'],
        ['Confirm the independence declarations of every engagement team member are on file and evaluate any threats identified, with safeguards.', null, 'ISA 220; By-Laws (MIA)', null],
        ['Establish that the financial reporting framework (MFRS, MPERS, MPSAS or other) is acceptable and that management acknowledges its responsibilities.', null, 'ISA 210', null],
        ['Obtain the signed engagement letter for this period, or document why the prior-year terms continue to apply without change.', null, 'ISA 210', 'Attach the signed letter as evidence and paste its document link.'],
        ['For a first-year audit, communicate with the predecessor auditor and record the response.', null, 'ISA 510; By-Laws (MIA)', 'Mark N/A in the conclusion where this is a continuing engagement.'],
    ]],
    ['A2', 'planning', 'engagement', null, 'Understanding the entity and its environment', 'Understand the entity, its environment, the applicable framework and its system of internal control well enough to identify and assess risks of material misstatement.', [
        ['Document the nature of the business, ownership and governance structure, key products, customers and suppliers, and the regulatory environment.', null, 'ISA 315 (Revised 2019)', 'Use the Entity understanding paper (B1) and link it here.'],
        ['Identify related parties and transactions with them, including directors\' interests and amounts due to or from them.', 'C, P', 'ISA 550', null],
        ['Understand the entity\'s accounting policies and any changes in the year, and assess whether they suit the framework.', 'P', 'ISA 315 (Revised 2019)', null],
        ['Understand the control environment, the entity\'s risk assessment process, monitoring, the information system and control activities relevant to the audit.', null, 'ISA 315 (Revised 2019)', 'Record walkthroughs in the controls programmes for each cycle.'],
        ['Understand the IT environment and the general IT controls over applications that produce information the audit relies on.', null, 'ISA 315 (Revised 2019)', null],
        ['Perform preliminary analytical procedures on the trial balance and record unusual movements for risk assessment.', null, 'ISA 315 (Revised 2019); ISA 520', 'Use the lead schedules for current vs prior year movements.'],
        ['Hold the engagement team discussion on susceptibility to material misstatement, including fraud, and record who attended and the outcome.', null, 'ISA 315 (Revised 2019); ISA 240', null],
    ]],
    ['A3', 'planning', 'engagement', null, 'Materiality', 'Determine and document overall, performance and specific materiality and the clearly trivial threshold.', [
        ['Select the benchmark and percentage with a documented rationale based on the users of the financial statements and the entity\'s circumstances.', null, 'ISA 320', 'Prepare in the Materiality module; approval is by the engagement partner.'],
        ['Set performance materiality reflecting the assessed risk, history of misstatements and expectations for the current year.', null, 'ISA 320', null],
        ['Consider whether specific materiality is needed for particular balances, transactions or disclosures.', null, 'ISA 320', null],
        ['Set the clearly trivial threshold for accumulating misstatements.', null, 'ISA 450', null],
        ['Revisit materiality when final figures are available and document the effect on the audit.', null, 'ISA 320; ISA 450', 'Complete at the completion stage.'],
    ]],
    ['A4', 'planning', 'engagement', null, 'Risk assessment, fraud and laws and regulations', 'Identify and assess risks of material misstatement at the financial statement and assertion levels, including fraud risks and significant risks.', [
        ['Record each identified risk in the Risk register with its assertions, inherent risk factors and control risk assessment.', null, 'ISA 315 (Revised 2019)', null],
        ['Presume a risk of fraud in revenue recognition and either respond to it or document why the presumption is rebutted.', 'O, CO', 'ISA 240', null],
        ['Address the risk of management override of controls: plan journal entry testing, review of estimates for bias and review of unusual transactions.', null, 'ISA 240', 'The journal testing step sits in the substantive programmes; link it here.'],
        ['Make inquiries of management and those charged with governance about fraud, suspected fraud and allegations.', null, 'ISA 240', null],
        ['Obtain an understanding of the legal and regulatory framework, including the Companies Act 2016, and identify laws with a direct effect on the financial statements.', 'P', 'ISA 250 (Revised)', null],
        ['Identify significant risks and submit them for partner approval.', null, 'ISA 315 (Revised 2019)', 'Approval is recorded through AUDIRA approvals.'],
    ]],
    ['A5', 'planning', 'engagement', null, 'Audit strategy, going concern and opening balances', 'Set the overall strategy and plan the nature, timing and extent of further audit procedures.', [
        ['Prepare the overall audit strategy: scope, reporting deadlines, team, use of experts, and areas of focus.', null, 'ISA 300', 'Prepare in the Audit strategy paper (D1).'],
        ['Decide for each significant cycle whether the audit will rely on controls, and record the reasons.', null, 'ISA 330', null],
        ['Perform a preliminary going-concern assessment: review management\'s assessment, cash flow forecasts, borrowings due and post year-end results.', 'V, P', 'ISA 570 (Revised)', null],
        ['For a first-year audit, plan procedures over opening balances and the consistency of accounting policies.', 'E, C, V', 'ISA 510', 'Mark N/A where not a first-year audit.'],
        ['Plan the use of the work of others (internal audit, experts, service organisations), where applicable.', null, 'ISA 610; ISA 620; ISA 402', null],
        ['Agree the planning timetable and the list of client documents (PBC) with management.', null, 'ISA 300', 'Raise the requests in PBC and link the request list.'],
    ]],

    // ───────────────────────────── CONTROLS TESTING (by cycle) ─────────────────────────────
    ['C-REV', 'controls', 'fsli', ['REV', 'TR', 'MC-INC', 'MCR'], 'Revenue and receivables cycle — controls', 'Evaluate the design and implementation of controls over sales, billing, collections and receivables and, where reliance is planned, test their operating effectiveness.', [
        ['Walk through one sale from order to cash receipt and document the controls observed at each stage.', 'O, C, A', 'ISA 315 (Revised 2019)', null],
        ['Evaluate whether controls over credit approval, price authorisation and invoicing are designed effectively and implemented.', 'O, A, V', 'ISA 315 (Revised 2019)', null],
        ['Test the control that every dispatch is invoiced (sequence or matching review) for a sample across the year.', 'C, CO', 'ISA 330; ISA 530', 'Use the Sampling module for the sample size and selection.'],
        ['Test the review and approval of credit notes and sales adjustments.', 'O, A', 'ISA 330', null],
        ['Test the monthly reconciliation of the receivables ledger to the general ledger and the review of the aged listing.', 'E, V', 'ISA 330', null],
        ['Evaluate deviations, conclude on reliance and update the planned substantive work.', null, 'ISA 330; ISA 530', null],
        ['Record any control deficiencies and decide which are significant for communication.', null, 'ISA 265', 'Raise significant deficiencies as Findings.'],
    ]],
    ['C-PUR', 'controls', 'fsli', ['COS', 'TP', 'OP'], 'Purchases and payables cycle — controls', 'Evaluate controls over ordering, receiving, invoice approval and payment.', [
        ['Walk through one purchase from requisition to payment and document the controls observed.', 'O, C, A', 'ISA 315 (Revised 2019)', null],
        ['Test the matching of supplier invoices to orders and goods received records before approval.', 'O, A', 'ISA 330; ISA 530', null],
        ['Test the authorisation of payments and segregation between approval and release of funds.', 'O', 'ISA 330', null],
        ['Test the monthly supplier statement reconciliations and the review of unmatched goods received.', 'C, CO', 'ISA 330', null],
        ['Test controls over changes to supplier master data, including bank details.', 'O', 'ISA 330', null],
        ['Evaluate deviations, conclude on reliance and record any deficiencies.', null, 'ISA 330; ISA 265', null],
    ]],
    ['C-PAY', 'controls', 'fsli', ['ADMIN'], 'Payroll cycle — controls', 'Evaluate controls over joiners, leavers, pay rates and payroll processing.', [
        ['Walk through the monthly payroll process and document the controls observed.', 'O, A', 'ISA 315 (Revised 2019)', null],
        ['Test that joiners, leavers and pay changes are authorised and supported for a sample of months.', 'O, A', 'ISA 330; ISA 530', null],
        ['Test the review of the payroll summary and its reconciliation to payments and statutory contributions (EPF, SOCSO, EIS, PCB).', 'C, A', 'ISA 330', null],
        ['Evaluate deviations, conclude on reliance and record any deficiencies.', null, 'ISA 330; ISA 265', null],
    ]],
    ['C-CASH', 'controls', 'fsli', ['CASH', 'FD', 'CASH-SF'], 'Cash and treasury — controls', 'Evaluate controls over bank accounts, receipts, payments and reconciliations.', [
        ['Document the bank accounts, signatories and online banking authorisation limits.', 'R', 'ISA 315 (Revised 2019)', null],
        ['Test the preparation and independent review of monthly bank reconciliations.', 'E, C', 'ISA 330', null],
        ['Test the authorisation of payment batches against the approved signatory limits.', 'O', 'ISA 330', null],
        ['Evaluate deviations, conclude on reliance and record any deficiencies.', null, 'ISA 330; ISA 265', null],
    ]],
    ['C-INV', 'controls', 'fsli', ['INV'], 'Inventory — controls', 'Evaluate controls over inventory movements, counts and costing.', [
        ['Walk through receipt, issue and recording of inventory, and the costing method applied.', 'E, C, V', 'ISA 315 (Revised 2019)', null],
        ['Evaluate the count instructions and controls over periodic or year-end counts.', 'E, C', 'ISA 501', null],
        ['Test the review of slow-moving and damaged stock and the approval of write-downs.', 'V', 'ISA 330', null],
        ['Evaluate deviations, conclude on reliance and record any deficiencies.', null, 'ISA 330; ISA 265', null],
    ]],
    ['C-PPE', 'controls', 'fsli', ['PPE', 'IP', 'INTANG'], 'Fixed assets — controls', 'Evaluate controls over capital expenditure, disposals and the asset register.', [
        ['Walk through the approval of capital expenditure and its recording in the asset register.', 'E, O, CL', 'ISA 315 (Revised 2019)', null],
        ['Test the reconciliation of the asset register to the general ledger and the periodic physical verification.', 'E, C', 'ISA 330', null],
        ['Evaluate deviations, conclude on reliance and record any deficiencies.', null, 'ISA 330; ISA 265', null],
    ]],

    // ─────────────────────────── SUBSTANTIVE (by FSLI code) ───────────────────────────
    ['S-PPE', 'substantive', 'fsli', ['PPE'], 'Property, plant and equipment', 'Obtain sufficient appropriate evidence that PPE exists, is owned, complete and properly valued and presented.', [
        ['Agree the opening balances to the prior-year audited financial statements and the closing balances to the lead schedule.', 'E, C', 'ISA 510; ISA 330', 'Link the lead schedule.'],
        ['Prepare or obtain the movement schedule (cost and accumulated depreciation) and agree it to the asset register.', 'C, A', 'ISA 500', null],
        ['Vouch additions above the testing threshold to invoices, contracts and approval; confirm capital in nature.', 'E, R, CL', 'ISA 500; ISA 530', 'Sample through the Sampling module where a sample is used.'],
        ['Vouch disposals to sale documents and recompute the gain or loss.', 'O, A', 'ISA 500', null],
        ['Physically sight a selection of significant assets, and trace a selection of assets seen on site to the register.', 'E, C', 'ISA 500', null],
        ['Inspect title documents, vehicle registration cards and charges registered over assets.', 'R, P', 'ISA 500', null],
        ['Recompute depreciation or perform an analytical review of the charge against expectations; assess useful lives and residual values.', 'V, A', 'ISA 520; ISA 540 (Revised)', null],
        ['Consider indicators of impairment and, where present, evaluate management\'s impairment assessment.', 'V', 'ISA 540 (Revised)', null],
        ['Check disclosures (policies, movements, pledged assets, commitments) against the framework.', 'P', 'ISA 330', null],
    ]],
    ['S-INV', 'substantive', 'fsli', ['INV'], 'Inventories', 'Obtain evidence that inventory exists, is complete, owned and stated at the lower of cost and net realisable value.', [
        ['Attend the inventory count (or an alternative date with roll-forward), observe the process and perform test counts both ways.', 'E, C', 'ISA 501', 'Attach count notes and paste the document link.'],
        ['Trace the test counts to the final inventory listing and investigate differences.', 'E, C, A', 'ISA 501', null],
        ['Test cut-off using the last goods received and dispatched documents before and after the year end.', 'CO', 'ISA 330', null],
        ['Test the costing of a selection of items to purchase invoices or costing records, including overhead absorption where applicable.', 'V, A', 'ISA 500', null],
        ['Test net realisable value by comparing cost with post year-end selling prices and reviewing slow-moving and obsolete items.', 'V', 'ISA 540 (Revised)', null],
        ['Obtain confirmations or inspect records for inventory held by third parties.', 'E, R', 'ISA 505; ISA 501', null],
        ['Check disclosures, including inventory pledged and the amount recognised as an expense.', 'P', 'ISA 330', null],
    ]],
    ['S-TR', 'substantive', 'fsli', ['TR', 'MCR'], 'Trade receivables', 'Obtain evidence that receivables exist, belong to the entity and are recoverable.', [
        ['Agree the aged receivables listing to the general ledger and cast it.', 'C, A', 'ISA 500', null],
        ['Send positive confirmations to a selection of customers, keeping control of the requests and replies throughout.', 'E, R', 'ISA 505', 'Generate the selection in Sampling; track replies through PBC.'],
        ['For non-replies, perform alternative procedures: subsequent receipts, invoices and delivery documents.', 'E', 'ISA 505', null],
        ['Test subsequent receipts after the year end against the year-end balances.', 'E, V', 'ISA 500', null],
        ['Test sales cut-off around the year end, including credit notes raised after the year end.', 'CO, E', 'ISA 330', null],
        ['Evaluate the expected credit loss allowance: model, inputs, ageing and specific provisions.', 'V', 'ISA 540 (Revised)', null],
        ['Investigate credit balances and reclassify where material.', 'CL, P', 'ISA 330', null],
        ['Check disclosures (credit risk, ageing, allowance movement) against the framework.', 'P', 'ISA 330', null],
    ]],
    ['S-OR', 'substantive', 'fsli', ['OR'], 'Other receivables, deposits and prepayments', 'Obtain evidence that other receivables, deposits and prepayments exist and are recoverable or will be consumed.', [
        ['Obtain the breakdown and agree it to the general ledger.', 'C, A', 'ISA 500', null],
        ['Vouch significant deposits and prepayments to agreements and payment evidence and recompute the prepaid portion.', 'E, V, CO', 'ISA 500', null],
        ['Assess recoverability of other receivables, including amounts long outstanding.', 'V', 'ISA 540 (Revised)', null],
        ['Check classification between current and non-current, and the disclosures.', 'CL, P', 'ISA 330', null],
    ]],
    ['S-RP', 'substantive', 'fsli', ['RPR', 'RPP'], 'Related party balances', 'Obtain evidence that related party balances are complete, exist and are appropriately disclosed.', [
        ['Agree the related party balances to the general ledger and to the related parties identified at planning.', 'C, A', 'ISA 550', null],
        ['Confirm significant balances with the related parties or inspect their records.', 'E, R', 'ISA 505; ISA 550', null],
        ['Review terms (interest, repayment, security) and assess recoverability of amounts due from related parties.', 'V', 'ISA 550', null],
        ['Search for unrecorded related party transactions in board minutes, bank statements and large or unusual entries.', 'C', 'ISA 550', null],
        ['Check disclosures of related party relationships, transactions and balances, including directors\' remuneration.', 'P', 'ISA 550', null],
    ]],
    ['S-CASH', 'substantive', 'fsli', ['CASH', 'FD', 'CASH-SF'], 'Cash, bank balances and deposits', 'Obtain evidence that cash and deposits exist, are owned and are complete.', [
        ['Obtain bank confirmations for every account (including accounts closed in the year) and control the requests and replies.', 'E, R, C', 'ISA 505', 'Paste the link to each confirmation reply.'],
        ['Agree the year-end bank reconciliations to the confirmations and the general ledger.', 'E, C, A', 'ISA 500', null],
        ['Trace reconciling items (unpresented cheques and deposits in transit) to post year-end bank statements.', 'E, CO', 'ISA 500', null],
        ['Count cash on hand where material, or agree to the count sheet.', 'E', 'ISA 500', null],
        ['Identify restricted balances, pledged deposits and facilities from the confirmations and check disclosure.', 'R, P', 'ISA 330', null],
    ]],
    ['S-EQ', 'substantive', 'fsli', ['SC', 'RES', 'RE', 'MF', 'SF'], 'Equity and funds', 'Obtain evidence that share capital, reserves and funds are properly recorded and disclosed.', [
        ['Agree the opening balances to the prior-year audited financial statements and analyse the movements.', 'C, A', 'ISA 510', null],
        ['Agree share capital to the register of members and SSM filings; vouch any issues in the year to resolutions and receipts.', 'E, R', 'ISA 500', null],
        ['Vouch dividends to directors\' resolutions and check the solvency test was performed (Companies Act 2016).', 'O, P', 'ISA 250 (Revised)', null],
        ['For strata bodies, verify that maintenance and sinking fund movements follow the governing rules.', 'O, CL', 'ISA 250 (Revised)', 'Mark N/A for companies.'],
        ['Check the statement of changes in equity and the related disclosures.', 'P', 'ISA 330', null],
    ]],
    ['S-BORR', 'substantive', 'fsli', ['BORR-C', 'BORR-NC', 'LEASE-C', 'LEASE-NC', 'ROU'], 'Borrowings, leases and right-of-use assets', 'Obtain evidence that borrowings and lease balances are complete, accurate and properly classified and disclosed.', [
        ['Agree the loan and lease schedules to the general ledger and to lender confirmations or statements.', 'C, E, A', 'ISA 505; ISA 500', null],
        ['Inspect new facility agreements for terms, security and covenants; check covenant compliance at the year end.', 'R, P', 'ISA 500', null],
        ['Recompute interest and finance charges, or perform an analytical review against average borrowings.', 'A, C', 'ISA 520', null],
        ['Check the split between current and non-current portions, including the effect of any covenant breach.', 'CL', 'ISA 330', null],
        ['Test the measurement of lease liabilities and right-of-use assets for significant leases (discount rate, term, payments).', 'V, A', 'ISA 540 (Revised)', null],
        ['Check disclosures of maturity, security, rates and lease movements.', 'P', 'ISA 330', null],
    ]],
    ['S-TP', 'substantive', 'fsli', ['TP'], 'Trade payables', 'Obtain evidence that trade payables are complete and accurately recorded.', [
        ['Agree the payables listing to the general ledger and cast it.', 'C, A', 'ISA 500', null],
        ['Reconcile a selection of supplier balances to supplier statements or confirmations, focusing on major suppliers including those with low balances.', 'C, E', 'ISA 505; ISA 500', null],
        ['Search for unrecorded liabilities: review post year-end payments and unprocessed invoices.', 'C, CO', 'ISA 330', null],
        ['Test purchases cut-off against goods received records around the year end.', 'CO', 'ISA 330', null],
        ['Investigate debit balances and reclassify where material; check disclosures.', 'CL, P', 'ISA 330', null],
    ]],
    ['S-OP', 'substantive', 'fsli', ['OP', 'DEP'], 'Other payables, accruals and deposits received', 'Obtain evidence that other payables and accruals are complete and accurate.', [
        ['Obtain the breakdown and agree it to the general ledger.', 'C, A', 'ISA 500', null],
        ['Vouch significant accruals to post year-end invoices or supporting calculations.', 'E, A, V', 'ISA 500', null],
        ['Search for unrecorded accruals using post year-end payments and expected recurring costs.', 'C', 'ISA 330', null],
        ['Consider provisions and contingent liabilities raised by legal letters, minutes and management representations.', 'C, V, P', 'ISA 501; ISA 540 (Revised)', null],
    ]],
    ['S-TAX', 'substantive', 'fsli', ['TAX', 'TAXPAY', 'TAXREC', 'DTA', 'DTL'], 'Taxation', 'Obtain evidence that current and deferred tax balances and the tax expense are accurately stated.', [
        ['Reconcile the tax payable or recoverable account: opening balance, payments (CP204 instalments), charge for the year.', 'C, A', 'ISA 500', null],
        ['Review the tax computation for the year and its basis (adjustments, capital allowances, reliefs).', 'A, V', 'ISA 500; ISA 620', 'Consider involving a tax specialist where the computation is complex.'],
        ['Recompute deferred tax on temporary differences and assess recoverability of deferred tax assets.', 'V', 'ISA 540 (Revised)', null],
        ['Prepare or review the reconciliation of tax expense to accounting profit at the statutory rate.', 'A, P', 'ISA 520', null],
        ['Check correspondence with LHDN for disputes or audits and the related disclosures.', 'C, P', 'ISA 250 (Revised)', null],
    ]],
    ['S-REV', 'substantive', 'fsli', ['REV', 'MC-INC', 'SF-INC'], 'Revenue', 'Obtain evidence that revenue occurred, is complete, accurate and recognised in the correct period.', [
        ['Perform a substantive analytical review of revenue by month, product or customer against expectations, investigating significant variances.', 'O, C, A', 'ISA 520', null],
        ['Vouch a sample of recorded sales to contracts, delivery evidence and receipts.', 'O, A', 'ISA 500; ISA 530', null],
        ['Trace a sample of delivery documents to recorded sales.', 'C', 'ISA 500; ISA 530', null],
        ['Test cut-off around the year end in both directions.', 'CO', 'ISA 330', null],
        ['Evaluate the revenue recognition policy for significant contracts against the framework (performance obligations and timing).', 'O, A, P', 'ISA 540 (Revised)', null],
        ['Respond to the presumed fraud risk in revenue: test manual journals to revenue and unusual year-end entries.', 'O', 'ISA 240', null],
        ['Check disclosures, including disaggregation of revenue where required.', 'P', 'ISA 330', null],
    ]],
    ['S-COS', 'substantive', 'fsli', ['COS'], 'Cost of sales', 'Obtain evidence that cost of sales is complete, occurred and is accurately recorded.', [
        ['Perform an analytical review of gross margin by month and against the prior year and budget.', 'C, A', 'ISA 520', null],
        ['Vouch a sample of purchases to invoices and goods received records.', 'O, A', 'ISA 500; ISA 530', null],
        ['Reconcile cost of sales to opening inventory, purchases and closing inventory.', 'C, A', 'ISA 500', null],
    ]],
    ['S-OI', 'substantive', 'fsli', ['OI'], 'Other income', 'Obtain evidence that other income occurred and is complete and properly classified.', [
        ['Analyse other income and compare with the prior year and expectations.', 'O, C', 'ISA 520', null],
        ['Vouch significant items to supporting documents (interest statements, rental agreements, disposal records).', 'O, A, CL', 'ISA 500', null],
    ]],
    ['S-FIN', 'substantive', 'fsli', ['FIN'], 'Finance costs', 'Obtain evidence that finance costs are complete and accurate.', [
        ['Perform a proof in total of interest against average borrowings and rates, or recompute for each facility.', 'C, A', 'ISA 520', 'Cross-reference to the borrowings programme.'],
        ['Check whether any borrowing costs should be capitalised on qualifying assets.', 'CL', 'ISA 540 (Revised)', null],
    ]],
    ['S-EXP', 'substantive', 'classification', 'expense', 'Operating expenses', 'Obtain evidence that operating expenses occurred, are complete, accurate and correctly classified.', [
        ['Perform an analytical review of expenses by nature against the prior year and expectations; investigate significant variances.', 'O, C, A', 'ISA 520', null],
        ['Vouch a sample of expenses to invoices and approval, checking the period and classification.', 'O, A, CO, CL', 'ISA 500; ISA 530', null],
        ['Test staff costs: reconcile payroll to the ledger and statutory returns (EPF, SOCSO, EIS, PCB), and analytically review the charge.', 'C, A', 'ISA 520; ISA 500', 'Mark N/A where staff costs are tested elsewhere.'],
        ['Verify directors\' remuneration and fees to approvals and check disclosure.', 'O, P', 'ISA 500; ISA 550', null],
    ]],

    // ─────────────────── SUBSTANTIVE fall-backs (any FSLI without its own programme) ───────────────────
    ['S-GNCA', 'substantive', 'classification', 'non_current_asset', 'Non-current assets (general)', 'Obtain evidence over a non-current asset balance that has no specific programme.', [
        ['Agree the opening and closing balances to the prior-year financial statements and the lead schedule; analyse the movements.', 'E, C, A', 'ISA 510; ISA 500', null],
        ['Vouch significant additions and disposals to supporting documents.', 'E, R, A', 'ISA 500', null],
        ['Evaluate measurement (fair value, amortisation or impairment) under the framework.', 'V', 'ISA 540 (Revised)', null],
        ['Check classification and disclosures.', 'CL, P', 'ISA 330', null],
    ]],
    ['S-GCA', 'substantive', 'classification', 'current_asset', 'Current assets (general)', 'Obtain evidence over a current asset balance that has no specific programme.', [
        ['Obtain the breakdown and agree it to the general ledger.', 'C, A', 'ISA 500', null],
        ['Confirm or vouch significant items to third-party evidence.', 'E, R', 'ISA 505; ISA 500', null],
        ['Assess recoverability or measurement at the year end.', 'V', 'ISA 540 (Revised)', null],
        ['Check classification and disclosures.', 'CL, P', 'ISA 330', null],
    ]],
    ['S-GLIAB', 'substantive', 'classification', 'current_liability', 'Current liabilities (general)', 'Obtain evidence over a current liability balance that has no specific programme.', [
        ['Obtain the breakdown and agree it to the general ledger.', 'C, A', 'ISA 500', null],
        ['Vouch significant items to supporting documents or confirmations.', 'E, A', 'ISA 505; ISA 500', null],
        ['Search for unrecorded items through post year-end payments.', 'C', 'ISA 330', null],
        ['Check classification and disclosures.', 'CL, P', 'ISA 330', null],
    ]],
    ['S-GNCL', 'substantive', 'classification', 'non_current_liability', 'Non-current liabilities (general)', 'Obtain evidence over a non-current liability balance that has no specific programme.', [
        ['Agree the balance to agreements, confirmations or calculations.', 'E, C, A', 'ISA 505; ISA 500', null],
        ['Evaluate measurement and the current / non-current split.', 'V, CL', 'ISA 540 (Revised)', null],
        ['Check disclosures.', 'P', 'ISA 330', null],
    ]],
    ['S-GEQ', 'substantive', 'classification', 'equity', 'Equity (general)', 'Obtain evidence over an equity balance that has no specific programme.', [
        ['Agree the opening balance and vouch the movements to approvals.', 'E, A', 'ISA 510; ISA 500', null],
        ['Check presentation and disclosures.', 'P', 'ISA 330', null],
    ]],
    ['S-GINC', 'substantive', 'classification', 'other_income', 'Income (general)', 'Obtain evidence over an income line that has no specific programme.', [
        ['Perform an analytical review against the prior year and expectations.', 'O, C', 'ISA 520', null],
        ['Vouch significant items to supporting documents.', 'O, A', 'ISA 500', null],
    ]],
    ['S-GREV', 'substantive', 'classification', 'revenue', 'Revenue (general)', 'Obtain evidence over a revenue line that has no specific programme.', [
        ['Perform an analytical review against the prior year and expectations.', 'O, C, A', 'ISA 520', null],
        ['Vouch a sample of transactions to supporting documents and test cut-off.', 'O, A, CO', 'ISA 500; ISA 530', null],
    ]],
];
