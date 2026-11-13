<?php

/*
|--------------------------------------------------------------------------
| AUDIRA platform configuration
|--------------------------------------------------------------------------
| Role keys: partner, manager, senior, associate, administrator (firm roles)
| and "firm_admin" (granted to users flagged is_firm_admin).
| Superadmin is a separate platform user type and is NOT listed here: it has no
| access to tenant audit data by design (docs/SECURITY.md).
*/

$allFirmRoles = ['partner', 'manager', 'senior', 'associate', 'administrator', 'firm_admin'];

return [

    'trial_days' => (int) env('AUDIRA_TRIAL_DAYS', 30),

    'invitation_ttl_days' => 7,

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Kuala_Lumpur'),

    'permissions' => [
        'firm.manage' => ['firm_admin'],
        'billing.view' => ['firm_admin', 'partner'],
        'users.view' => ['firm_admin', 'partner', 'manager'],
        'users.manage' => ['firm_admin'],
        'activity.view' => ['firm_admin', 'partner'],
        'ai.manage' => ['firm_admin'],

        'clients.view' => $allFirmRoles,
        'clients.create' => ['firm_admin', 'partner', 'manager'],
        'clients.update' => ['firm_admin', 'partner', 'manager'],
        'clients.delete' => ['firm_admin', 'partner'],
        'clients.submit_acceptance' => ['partner', 'manager'],

        'engagements.view_all' => ['firm_admin', 'partner'],
        'engagements.create' => ['partner', 'manager'],
        // Client portal access (Phase 7). Portal users are free and see only their client's requests.
        'clients.manage_portal' => ['firm_admin', 'partner', 'manager'],
        // The firm's approved auditor's-report wording (Phase 9).
        'report_templates.manage' => ['firm_admin', 'partner'],
        // Break-glass support access is granted only by firm admins (Phase 10).
        'support.grant' => ['firm_admin'],
    ],

    /*
    | Engagement-level abilities, granted by the user's role ON THAT ENGAGEMENT.
    */
    'engagement_permissions' => [
        'engagement.view' => ['engagement_partner', 'eqr_reviewer', 'manager', 'senior', 'staff'],
        'engagement.update' => ['engagement_partner', 'manager'],
        'engagement.manage_team' => ['engagement_partner', 'manager'],
        'engagement.change_status' => ['engagement_partner', 'manager'],
        // Working papers & documents (Phase 2). EQR reviewers are independent: read + review notes only.
        'engagement.edit_working_papers' => ['engagement_partner', 'manager', 'senior', 'staff'],
        'engagement.prepare_working_papers' => ['engagement_partner', 'manager', 'senior', 'staff'],
        'engagement.review_working_papers' => ['engagement_partner', 'manager'],
        'engagement.raise_review_notes' => ['engagement_partner', 'manager', 'eqr_reviewer', 'senior'],
        'engagement.upload_documents' => ['engagement_partner', 'manager', 'senior', 'staff'],
        'engagement.archive_documents' => ['engagement_partner', 'manager'],
        // Trial balance, mapping and adjustments (Phase 3). Posting requires a reviewer-level role,
        // and the preparer of an adjustment can never post it (DB constraint).
        'engagement.import_trial_balance' => ['engagement_partner', 'manager', 'senior'],
        'engagement.map_accounts' => ['engagement_partner', 'manager', 'senior', 'staff'],
        'engagement.manage_fslis' => ['engagement_partner', 'manager', 'senior'],
        'engagement.prepare_adjustments' => ['engagement_partner', 'manager', 'senior', 'staff'],
        'engagement.post_adjustments' => ['engagement_partner', 'manager'],
        // Planning (Phase 5). Materiality and significant risks are APPROVED by the engagement
        // partner through the approvals engine (see approval_engagement_roles below).
        'engagement.prepare_materiality' => ['engagement_partner', 'manager', 'senior'],
        'engagement.manage_risks' => ['engagement_partner', 'manager', 'senior'],
        'engagement.edit_strategy' => ['engagement_partner', 'manager'],
        // Programme and sampling (Phase 6). Sampling plans are approved by the engagement partner
        // or manager (approval_engagement_roles.sampling_plan) — never by the requester.
        'engagement.manage_programme' => ['engagement_partner', 'manager', 'senior'],
        'engagement.execute_procedures' => ['engagement_partner', 'manager', 'senior', 'staff'],
        'engagement.manage_sampling' => ['engagement_partner', 'manager', 'senior'],
        // Client requests and correspondence (Phase 7). Every outbound message is sent by a person.
        'engagement.manage_pbc' => ['engagement_partner', 'manager', 'senior'],
        'engagement.message_client' => ['engagement_partner', 'manager', 'senior'],
        // Testing and findings (Phase 8). Significant findings are approved by the engagement partner.
        'engagement.manage_findings' => ['engagement_partner', 'manager', 'senior'],
        // Completion and reporting (Phase 9). Final conclusions, completion, the opinion and report
        // sign-off are DECIDED by the engagement partner through approvals; the EQR reviewer records
        // the engagement quality review.
        'engagement.manage_completion' => ['engagement_partner', 'manager', 'senior'],
        'engagement.prepare_report' => ['engagement_partner', 'manager'],
        'engagement.eqr_review' => ['eqr_reviewer'],
    ],

    /*
    | Who may DECIDE each approval type (firm roles). Requester can never decide their own
    | request. Engagement-scoped types additionally require the decider to be the engagement
    | partner (enforced in ApprovalService from Phase 5 when those approvables exist).
    */
    /*
    | Multi-factor authentication. Required for these roles (firm roles, 'firm_admin', 'superadmin').
    | AUDIRA_MFA_ENFORCED=false is only for automated tests of unrelated features.
    */
    'mfa' => [
        'enforced' => (bool) env('AUDIRA_MFA_ENFORCED', true),
        'required_for' => ['superadmin', 'firm_admin', 'partner'],
        'issuer' => env('APP_NAME', 'AUDIRA'),
        'recovery_codes' => 8,
    ],

    'documents' => [
        'disk' => env('AUDIRA_DOCUMENTS_DISK', 'documents'),
        'max_kb' => (int) env('AUDIRA_MAX_UPLOAD_KB', 51200),
        // Extension allow-list; the content-sniffed MIME type must also match (Laravel "mimes" rule).
        'extensions' => ['pdf', 'xlsx', 'xls', 'csv', 'docx', 'doc', 'png', 'jpg', 'jpeg', 'txt'],
        // Content-sniffed MIME types accepted for each extension (a renamed file is rejected).
        'mime_map' => [
            'pdf' => ['application/pdf'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/cdf-v2', 'application/x-ole-storage'],
            'doc' => ['application/msword', 'application/cdf-v2', 'application/x-ole-storage'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'txt' => ['text/plain'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
        ],
    ],

    /*
    | Malware scanning: "clamd" (production — ClamAV daemon over TCP) or "basic" (development only:
    | blocks executables, scripts and the EICAR test signature; NOT a substitute for antivirus).
    */
    'scanner' => [
        'driver' => env('AUDIRA_SCANNER', 'basic'),
        'clamd_host' => env('CLAMD_HOST', '127.0.0.1'),
        'clamd_port' => (int) env('CLAMD_PORT', 3310),
        'timeout' => 30,
    ],

    /*
    | Entity understanding template. Topics follow the structure of ISA 315 (Revised 2019);
    | authoritative requirements are cited from the Audit Library, never reproduced here.
    */
    'entity_understanding' => [
        'nature_of_entity' => ['Nature of the entity', 'Legal form, ownership and governance structure, organisational structure, operations, locations, investments and financing.'],
        'business_model' => ['Objectives, strategy and business model', 'How the entity creates value and the business risks that could result in material misstatement.'],
        'industry_regulatory' => ['Industry, regulatory and other external factors', 'Industry conditions, competition, regulatory environment (e.g. Companies Act 2016, Strata Management Act 2013 for JMB/MC, SKM requirements for cooperatives), taxation, economic conditions.'],
        'performance_measures' => ['Measures used to assess financial performance', 'KPIs, budgets, variance analysis and any performance-based incentives that create pressure on reporting.'],
        'framework_policies' => ['Applicable framework and accounting policies', 'Framework (MFRS / MPERS / MPSAS / other), significant accounting policies, changes and the reasons for them.'],
        'control_environment' => ['Control environment', 'Integrity and ethical values, oversight by those charged with governance, competence, accountability.'],
        'risk_assessment_process' => ["Entity's risk assessment process", 'How management identifies, assesses and responds to business risks relevant to financial reporting.'],
        'monitoring' => ['Process to monitor the system of internal control', 'Ongoing and separate evaluations, internal audit function, remediation of deficiencies.'],
        'information_system' => ['Information system and communication', 'Flows of transactions, records, the financial reporting process and journal entry controls.'],
        'control_activities' => ['Control activities', 'Controls relevant to the audit, including those addressing significant risks and journal entries.'],
        'it_environment' => ['IT environment', 'Accounting software, IT general controls, service organisations and cloud applications.'],
        'related_parties' => ['Related parties', 'Identified related parties and the nature of relationships and transactions.'],
        'laws_regulations' => ['Laws and regulations', 'Laws with a direct effect on the financial statements and others fundamental to operations.'],
        'fraud_risk_factors' => ['Fraud risk factors', 'Incentives/pressures, opportunities and attitudes/rationalisations identified; discussion among the engagement team.'],
        'going_concern' => ['Going concern indicators', 'Events or conditions that may cast significant doubt on the ability to continue as a going concern.'],
    ],

    /*
    | Trial balance import limits and deterministic mapping suggestions. Suggestions are never
    | applied automatically: a team member accepts them, and every mapping records its source.
    | Patterns are case-insensitive regexes matched against the account name; first match wins.
    */
    'trial_balance' => [
        'max_rows' => 20000,
        'max_kb' => 10240,
        'mapping_rules' => [
            // Strata bodies (JMB / MC) — only apply where the engagement uses the strata template.
            '/sinking fund (contribution|collection|charge)s?$/' => 'SF-INC',
            '/(maintenance|sinking fund).*(receivable|arrears)|arrears/' => 'MCR',
            '/^maintenance (charges?|fees?)( income| collected)?$/' => 'MC-INC',
            '/sinking fund.*(bank|account|fixed deposit)|bank.*sinking fund/' => 'CASH-SF',
            '/^sinking fund$/' => 'SF',
            '/accumulated surplus/' => 'MF',
            '/security (services?|guard)/' => 'SEC',
            '/cleaning|landscap/' => 'CLEAN',
            '/electricity|water (bill|charges)|utilit/' => 'UTIL',
            '/lift maintenance|repair|maintenance of/' => 'RM',
            '/accum.*deprec|property, plant|plant and machinery|motor vehicle|furniture|office equipment|renovation|computer equipment|land and building/' => 'PPE',
            '/right[- ]of[- ]use/' => 'ROU',
            '/investment propert/' => 'IP',
            '/goodwill|intangible|software licen/' => 'INTANG',
            '/deferred tax/' => null, // sign-dependent: decide manually
            '/inventor|stock[- ]in[- ]trade|raw material|finished goods|work[- ]in[- ]progress/' => 'INV',
            '/trade receivable|trade debtor|debtors? control|allowance for (doubtful|impairment|expected credit)/' => 'TR',
            '/prepayment|deposit paid|other receivable|other debtor|sundry debtor/' => 'OR',
            '/fixed deposit/' => 'FD',
            '/overdraft/' => null, // borrowings vs cash: presentation judgement, map manually
            '/\bbank\b|cash in hand|petty cash|cash at bank/' => 'CASH',
            '/share capital|ordinary shares/' => 'SC',
            '/retained (earning|profit)|accumulated (loss|profit)|unappropriated/' => 'RE',
            // Interest must be tested before the borrowing rule below (which deliberately maps nothing).
            '/interest expense|loan interest|finance cost|hire purchase interest|bank charges? on loan|interest on/' => 'FIN',
            '/hire purchase|term loan|bank borrowing|revolving credit|overdraft/' => null, // current vs non-current split: manual
            '/lease liabilit/' => null,
            '/trade payable|trade creditor|creditors? control/' => 'TP',
            '/accrual|accrued|other payable|other creditor|sundry creditor|deposit received/' => 'OP',
            '/amount due to director|director.s? account|due to related/' => 'RPP',
            '/amount due from|due from related/' => 'RPR',
            '/provision for tax|tax payable|income tax payable/' => 'TAXPAY',
            '/tax recoverable|tax prepaid/' => 'TAXREC',
            '/^sales|revenue|turnover/' => 'REV',
            '/cost of (sales|goods)|purchases|opening stock|closing stock|direct labou?r|freight inward|factory overhead|production overhead/' => 'COS',
            '/interest income|dividend income|rental income|gain on disposal|other income|sundry income/' => 'OI',
            '/income tax expense|taxation|tax expense/' => 'TAX',
            '/salar|wages|epf|socso|eis|rental of|utilit|audit fee|depreciation|secretarial|professional fee|insurance|upkeep|telephone|printing|stationery|travel/' => 'ADMIN',
            '/advertis|promotion|commission|transport outward|delivery/' => 'SELL',
        ],
    ],

    /*
    | Engagement-scoped approvals: besides the firm role above, the decider must hold one of these
    | roles ON the engagement. The requester can never decide, so the engagement partner cannot
    | submit what only they may approve.
    */
    'approval_engagement_roles' => [
        'materiality' => ['engagement_partner'],
        'significant_risk' => ['engagement_partner'],
        'sampling_plan' => ['engagement_partner', 'manager'],
        'material_adjustment' => ['engagement_partner'],
        'significant_finding' => ['engagement_partner'],
        'final_conclusions' => ['engagement_partner'],
        'completion' => ['engagement_partner'],
        'audit_opinion' => ['engagement_partner'],
        'report_signoff' => ['engagement_partner'],
    ],

    /*
    | Material adjustments (Phase 8). An adjustment whose total debits are at or above this basis of
    | the APPROVED materiality needs engagement-partner approval before posting: 'om' overall,
    | 'pm' performance materiality, 'ct' clearly trivial, 'all' every adjustment. Without approved
    | materiality every adjustment needs approval. The basis is a firm-methodology choice.
    */
    'material_adjustment' => [
        'basis' => env('AUDIRA_MATERIAL_ADJUSTMENT_BASIS', 'pm'),
    ],

    /*
    | Approvals that require the decider to re-enter their password (and MFA code when enrolled)
    | at the moment of approving (Phase 9).
    */
    /*
    | Billing (Phase 10). The payment gateway is pluggable (App\Services\Billing\Gateways); only the
    | "manual" gateway (bank transfer, payment recorded by platform staff) is implemented until the
    | gateway decision is made. Tax is NOT assumed: set the tax name and rate once the platform's
    | tax position has been confirmed with an adviser (both empty = no tax line).
    */
    'billing' => [
        'gateway' => env('AUDIRA_BILLING_GATEWAY', 'manual'),
        'payment_terms_days' => (int) env('AUDIRA_PAYMENT_TERMS_DAYS', 14),
        'grace_days' => (int) env('AUDIRA_BILLING_GRACE_DAYS', 14),
        'invoice_prefix' => env('AUDIRA_INVOICE_PREFIX', 'AUD'),
        'tax' => [
            'name' => env('AUDIRA_TAX_NAME'),
            'rate' => env('AUDIRA_TAX_RATE') !== null ? (float) env('AUDIRA_TAX_RATE') : null,
        ],
        'manual' => [
            'instructions' => env('AUDIRA_PAYMENT_INSTRUCTIONS', 'Pay by bank transfer using the invoice number as the payment reference. Bank details are provided by AUDIRA billing on request.'),
        ],
    ],

    /*
    | Engagement monitoring (Phase 10): thresholds for daily alerts.
    */
    'monitoring' => [
        'deadline_warning_days' => 14,
        'stale_approval_days' => 7,
        'stale_review_note_days' => 14,
    ],

    /*
    | Break-glass support access (Phase 10): maximum window a firm admin can grant.
    */
    'support_access_max_hours' => 72,

    'reauth_approval_types' => ['audit_opinion', 'report_signoff'],

    /*
    | Completion checklist template (Phase 9): key => [section, title]. Topics follow the
    | completion requirements of the ISAs named in each section; they are prompts for the team's
    | documented response, not quotations of the standards. Firms may extend this list.
    */
    'completion' => [
        'items' => [
            'subsequent_events' => ['Subsequent events (ISA 560)', 'Procedures performed to identify events between the period end and the date of the auditor\'s report, and their treatment'],
            'going_concern' => ['Going concern (ISA 570)', 'Evaluation of management\'s assessment of going concern, events or conditions identified and whether a material uncertainty exists'],
            'written_representations' => ['Written representations (ISA 580)', 'Representation letter requested, its date and any matters not represented'],
            'uncorrected_misstatements' => ['Misstatements (ISA 450)', 'Uncorrected misstatements communicated to management and those charged with governance, and their effect evaluated individually and in aggregate'],
            'tcwg_communication' => ['Communication (ISA 260, ISA 265)', 'Matters communicated to those charged with governance, including significant deficiencies in internal control'],
            'related_parties' => ['Related parties (ISA 550)', 'Related party relationships and transactions identified, and their disclosure'],
            'laws_regulations' => ['Laws and regulations (ISA 250)', 'Non-compliance identified or suspected and its effect'],
            'litigation' => ['Litigation and claims (ISA 501)', 'Litigation and claims identified and the evidence obtained'],
            'estimates' => ['Accounting estimates (ISA 540)', 'Evaluation of significant accounting estimates and indicators of management bias'],
            'final_analytics' => ['Overall review (ISA 520)', 'Analytical procedures near the end of the audit and whether the financial statements are consistent with the team\'s understanding'],
            'disclosures' => ['Presentation and disclosure', 'Financial statements and disclosures checked against the applicable reporting framework and statutory requirements'],
            'independence' => ['Ethics and independence', 'Independence and ethical requirements confirmed for the engagement team up to the report date'],
        ],
    ],

    /*
    | Overall audit strategy template (working paper A1): key => [title, guidance]. Topics follow
    | the planning requirements of ISA 300; cite authoritative paragraphs from the Audit Library.
    */
    'audit_strategy' => [
        'sections' => [
            'scope' => ['Scope and characteristics of the engagement', 'Reporting framework, statutory and regulatory reporting requirements, components and locations, expected audit coverage.'],
            'reporting' => ['Reporting objectives, timing and communications', 'Reporting deadlines, key dates, and planned communications with management and those charged with governance.'],
            'significant_factors' => ['Significant factors and preliminary engagement activities', 'Matters that direct the team\'s efforts: results of acceptance/continuance and independence, prior-year findings, changes in the business, knowledge gained on other engagements.'],
            'overall_responses' => ['Overall responses to risks at the financial statement level', 'Assignment and supervision of staff, professional scepticism, unpredictability, changes to the nature, timing and extent of procedures.'],
            'resources' => ['Nature, timing and extent of resources', 'Engagement team, experience and hours; specialists or experts; engagement quality review; budget.'],
            'reliance' => ['Use of others and reliance on controls', 'Internal audit, management\'s or auditor\'s experts, service organisations, and whether a combined (controls reliance) or substantive approach is planned.'],
        ],
        'required' => ['scope', 'overall_responses', 'resources'],
    ],

    'approval_deciders' => [
        'client_acceptance' => ['partner'],
        'materiality' => ['partner'],
        'significant_risk' => ['partner'],
        'sampling_plan' => ['partner', 'manager'],
        'material_adjustment' => ['partner'],
        'significant_finding' => ['partner'],
        'final_conclusions' => ['partner'],
        'completion' => ['partner'],
        'audit_opinion' => ['partner'],
        'report_signoff' => ['partner'],
    ],
];
