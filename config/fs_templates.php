<?php

/*
| Starting layouts for management-prepared financial statements (Phase 12). Captions only —
| presentation and disclosure requirements come from the applicable framework, which the
| client (management) is responsible for applying. The client edits, adds and removes lines.
|
| Statement rows: [kind, caption] where kind is heading | line | subtotal. SOFP rows also carry a
| side: 'asset' or 'el' (equity and liabilities). Amounts on SOPL / SOCE / cash flow rows are
| signed: income and inflows positive, expenses and outflows negative (type them in brackets).
*/

$company = [
    'statements' => [
        'sofp' => ['label' => 'Statement of financial position', 'rows' => [
            ['heading', 'Non-current assets', 'asset'], ['line', 'Property, plant and equipment', 'asset'], ['line', 'Right-of-use assets', 'asset'],
            ['line', 'Investment properties', 'asset'], ['line', 'Intangible assets', 'asset'], ['line', 'Deferred tax assets', 'asset'],
            ['heading', 'Current assets', 'asset'], ['line', 'Inventories', 'asset'], ['line', 'Trade receivables', 'asset'],
            ['line', 'Other receivables, deposits and prepayments', 'asset'], ['line', 'Tax recoverable', 'asset'], ['line', 'Cash and bank balances', 'asset'],
            ['heading', 'Equity', 'el'], ['line', 'Share capital', 'el'], ['line', 'Retained earnings', 'el'],
            ['heading', 'Non-current liabilities', 'el'], ['line', 'Borrowings', 'el'], ['line', 'Lease liabilities', 'el'], ['line', 'Deferred tax liabilities', 'el'],
            ['heading', 'Current liabilities', 'el'], ['line', 'Trade payables', 'el'], ['line', 'Other payables and accruals', 'el'],
            ['line', 'Borrowings (current)', 'el'], ['line', 'Tax payable', 'el'],
        ]],
        'sopl' => ['label' => 'Statement of profit or loss and other comprehensive income', 'rows' => [
            ['line', 'Revenue'], ['line', 'Cost of sales'], ['subtotal', 'Gross profit'], ['line', 'Other income'],
            ['line', 'Administrative expenses'], ['line', 'Selling and distribution expenses'], ['line', 'Other operating expenses'],
            ['line', 'Finance costs'], ['subtotal', 'Profit before tax'], ['line', 'Income tax expense'], ['subtotal', 'Profit for the year'],
        ]],
        'soce' => ['label' => 'Statement of changes in equity', 'rows' => [
            ['subtotal', 'Balance at beginning of the year'], ['line', 'Profit for the year'], ['line', 'Dividends'], ['line', 'Issue of shares'],
            ['subtotal', 'Balance at end of the year'],
        ]],
        'cfs' => ['label' => 'Statement of cash flows', 'rows' => [
            ['heading', 'Cash flows from operating activities'], ['line', 'Profit before tax'], ['line', 'Depreciation'], ['line', 'Changes in working capital'],
            ['line', 'Tax paid'], ['subtotal', 'Net cash from operating activities'],
            ['heading', 'Cash flows from investing activities'], ['line', 'Purchase of property, plant and equipment'], ['subtotal', 'Net cash used in investing activities'],
            ['heading', 'Cash flows from financing activities'], ['line', 'Drawdown / (repayment) of borrowings'], ['line', 'Dividends paid'], ['subtotal', 'Net cash from financing activities'],
            ['subtotal', 'Net change in cash and cash equivalents'], ['subtotal', 'Cash and cash equivalents at beginning of the year'], ['subtotal', 'Cash and cash equivalents at end of the year'],
        ]],
    ],
    'notes' => ['Corporate information', 'Basis of preparation', 'Material accounting policy information', 'Property, plant and equipment', 'Trade receivables',
        'Cash and bank balances', 'Share capital', 'Borrowings', 'Trade and other payables', 'Revenue', 'Profit before tax', 'Income tax expense',
        'Related party disclosures', 'Financial instruments', 'Events after the reporting period'],
];

return [
    'company' => ['label' => 'Company (MFRS / MPERS)', ...$company],
    'cooperative' => ['label' => 'Cooperative', 'statements' => [
        ...$company['statements'],
        'sofp' => ['label' => 'Statement of financial position', 'rows' => [
            ['heading', 'Non-current assets', 'asset'], ['line', 'Property, plant and equipment', 'asset'], ['line', 'Investments', 'asset'],
            ['heading', 'Current assets', 'asset'], ['line', 'Inventories', 'asset'], ['line', 'Members\' loans and financing', 'asset'], ['line', 'Trade and other receivables', 'asset'],
            ['line', 'Cash and bank balances', 'asset'],
            ['heading', 'Members\' funds', 'el'], ['line', 'Share capital', 'el'], ['line', 'Statutory reserve fund', 'el'], ['line', 'Other reserves', 'el'], ['line', 'Retained earnings', 'el'],
            ['heading', 'Liabilities', 'el'], ['line', 'Borrowings', 'el'], ['line', 'Trade and other payables', 'el'], ['line', 'Tax payable', 'el'],
        ]],
        'soce' => ['label' => 'Statement of changes in members\' funds', 'rows' => [
            ['subtotal', 'Balance at beginning of the year'], ['line', 'Profit for the year'], ['line', 'Transfer to statutory reserve fund'], ['line', 'Dividends / bonuses'],
            ['line', 'Shares issued / (withdrawn)'], ['subtotal', 'Balance at end of the year'],
        ]],
    ], 'notes' => ['Cooperative information', 'Basis of preparation', 'Material accounting policy information', 'Property, plant and equipment', 'Members\' loans and financing',
        'Share capital', 'Statutory reserve fund', 'Borrowings', 'Revenue', 'Related party disclosures', 'Events after the reporting period']],
    'jmb_mc' => ['label' => 'Strata body (JMB / MC)', 'statements' => [
        'sofp' => ['label' => 'Statement of financial position', 'rows' => [
            ['heading', 'Non-current assets', 'asset'], ['line', 'Property, plant and equipment', 'asset'],
            ['heading', 'Current assets', 'asset'], ['line', 'Maintenance charges and sinking fund receivable', 'asset'], ['line', 'Other receivables, deposits and prepayments', 'asset'],
            ['line', 'Fixed deposits with licensed banks', 'asset'], ['line', 'Cash and bank balances — maintenance fund', 'asset'], ['line', 'Cash and bank balances — sinking fund', 'asset'],
            ['heading', 'Funds', 'el'], ['line', 'Maintenance fund — accumulated surplus', 'el'], ['line', 'Sinking fund', 'el'],
            ['heading', 'Current liabilities', 'el'], ['line', 'Payables and accruals', 'el'], ['line', 'Deposits and advance collections', 'el'],
        ]],
        'sopl' => ['label' => 'Statement of income and expenditure', 'rows' => [
            ['heading', 'Maintenance fund'], ['line', 'Maintenance charges'], ['line', 'Other income'], ['line', 'Security services'], ['line', 'Cleaning and landscaping'],
            ['line', 'Utilities'], ['line', 'Repairs and maintenance'], ['line', 'Management and administrative expenses'], ['subtotal', 'Surplus / (deficit) for the year — maintenance fund'],
            ['heading', 'Sinking fund'], ['line', 'Sinking fund contributions'], ['line', 'Sinking fund expenditure'], ['subtotal', 'Surplus / (deficit) for the year — sinking fund'],
        ]],
        'soce' => ['label' => 'Statement of changes in funds', 'rows' => [
            ['subtotal', 'Balance at beginning of the year'], ['line', 'Surplus / (deficit) for the year'], ['subtotal', 'Balance at end of the year'],
        ]],
        'cfs' => $company['statements']['cfs'],
    ], 'notes' => ['General information', 'Basis of preparation', 'Material accounting policy information', 'Maintenance charges and sinking fund receivable',
        'Cash and bank balances', 'Sinking fund', 'Payables and accruals', 'Events after the reporting period']],
];
