<?php

/*
| Ready-made client request lists (Phase 12). AUDIRA's own wording; these are common audit
| requests, not regulatory requirements. Firms adapt the draft items before sending them.
| Each item: [title, category (PbcCategory value), description shown to the client].
*/

$common = [
    ['Trial balance as at year end', 'financial', 'Final trial balance at the financial year end, in Excel, agreeing to the general ledger.'],
    ['General ledger for the year', 'financial', 'Full general ledger listing for the financial year (Excel or CSV).'],
    ['Bank statements for all accounts', 'banking', 'Statements for every bank account for the full year and the first two months after year end, including accounts closed during the year.'],
    ['Bank reconciliations at year end', 'banking', 'Year-end reconciliation for each bank account, with the supporting list of unpresented and uncredited items.'],
    ['Fixed asset register', 'fixed_assets', 'Asset register showing cost, additions, disposals, depreciation and net book value, agreeing to the ledger.'],
    ['Invoices for major additions and disposals', 'fixed_assets', 'Supporting invoices, agreements or sale documents for significant asset additions and disposals in the year.'],
    ['Minutes of meetings', 'governance', 'Minutes of board / committee and general meetings held during the year and up to today.'],
    ['Agreements and contracts entered into', 'legal', 'Significant agreements signed during the year (loans, leases, major customer or supplier contracts).'],
    ['Tax computation and correspondence', 'tax', 'Latest tax computation, returns filed, instalment payment records and any correspondence with LHDN.'],
    ['Payroll summary and statutory contributions', 'payroll', 'Monthly payroll summaries and evidence of EPF, SOCSO, EIS and PCB payments for the year.'],
    ['Schedule of accruals and other payables', 'payables', 'Breakdown of accruals and other payables at year end with supporting documents.'],
    ['Schedule of deposits and prepayments', 'receivables', 'Breakdown of deposits, prepayments and other receivables at year end with supporting documents.'],
];

return [
    'company' => [
        'label' => 'Companies (Sdn. Bhd. / Bhd.)',
        'items' => [
            ...$common,
            ['Aged trade receivables listing', 'receivables', 'Aged listing of trade receivables at year end, agreeing to the ledger, with customer contact details for confirmations.'],
            ['Aged trade payables listing', 'payables', 'Aged listing of trade payables at year end, agreeing to the ledger, with supplier statements where available.'],
            ['Inventory listing and count sheets', 'inventory', 'Year-end inventory listing with quantities and costs, the count instructions and the signed count sheets.'],
            ['Sales and purchases cut-off documents', 'financial', 'Last five sales invoices / delivery orders and goods received notes before and after year end.'],
            ['Loan and hire purchase statements', 'banking', 'Statements and facility letters for all borrowings, hire purchase and leases at year end.'],
            ['Register of members and directors', 'governance', 'Current registers of members and directors, and filings with SSM made during the year.'],
            ['Related party transactions and balances', 'governance', 'List of related parties and of transactions and balances with them during the year, including directors.'],
            ['Draft financial statements', 'financial', 'Management\'s draft financial statements for the year, prepared in the client portal or uploaded here.'],
        ],
    ],
    'jmb_mc' => [
        'label' => 'Strata bodies (JMB / MC)',
        'items' => [
            ...$common,
            ['Maintenance charges and sinking fund billing listing', 'receivables', 'Billing listing per parcel for maintenance charges and sinking fund contributions for the year.'],
            ['Arrears listing by parcel', 'receivables', 'Listing of outstanding charges and contributions by parcel at year end, with ageing.'],
            ['Separate bank accounts for maintenance and sinking funds', 'banking', 'Statements and year-end reconciliations for the maintenance fund and sinking fund accounts separately.'],
            ['Service contracts', 'legal', 'Contracts for security, cleaning, landscaping, lifts and other recurring services.'],
            ['Minutes of committee meetings and AGM', 'governance', 'Minutes of management committee meetings and the annual general meeting, including approved budgets and charge rates.'],
            ['Deposits and advance collections listing', 'payables', 'Listing of deposits received and charges collected in advance at year end.'],
            ['Sinking fund expenditure approvals', 'governance', 'Approvals and supporting documents for any spending charged to the sinking fund.'],
        ],
    ],
    'cooperative' => [
        'label' => 'Cooperatives',
        'items' => [
            ...$common,
            ['Register of members and share capital', 'governance', 'Register of members with shares and subscriptions held at year end.'],
            ['Members\' loans and financing listing', 'receivables', 'Listing of loans or financing to members at year end with ageing and repayment status.'],
            ['Minutes of board and annual general meetings', 'governance', 'Minutes of board meetings and the annual general meeting, including approved dividends or bonuses.'],
            ['Statutory and reserve fund movements', 'financial', 'Schedule of movements in statutory and other reserve funds, with the supporting resolutions.'],
            ['Correspondence with the regulator', 'legal', 'Correspondence with the cooperative regulator during the year and up to today.'],
            ['Aged trade receivables and payables listings', 'receivables', 'Aged listings of trade receivables and payables at year end, agreeing to the ledger.'],
        ],
    ],
];
