<?php

namespace Database\Seeders;

use App\Models\FsliTemplate;
use Illuminate\Database\Seeder;

/**
 * Generic financial statement captions commonly used by Malaysian companies. These are presentation
 * captions for grouping a trial balance, not requirements of any standard; engagements copy and
 * adapt them (add, rename, remove unused). Framework-specific disclosure requirements come from the
 * Audit Library, never from this list.
 */
class FsliTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->strata();

        $template = FsliTemplate::firstOrCreate(['code' => 'corporate'], [
            'name' => 'Corporate entities (MFRS / MPERS)',
            'description' => 'General-purpose captions for companies; adapt per engagement.',
            'is_default' => true,
        ]);
        if ($template->items()->exists()) {
            return;
        }

        $items = [
            ['PPE', 'Property, plant and equipment', 'non_current_asset'],
            ['ROU', 'Right-of-use assets', 'non_current_asset'],
            ['IP', 'Investment properties', 'non_current_asset'],
            ['INTANG', 'Intangible assets', 'non_current_asset'],
            ['INVEST', 'Investments', 'non_current_asset'],
            ['DTA', 'Deferred tax assets', 'non_current_asset'],
            ['INV', 'Inventories', 'current_asset'],
            ['TR', 'Trade receivables', 'current_asset'],
            ['OR', 'Other receivables, deposits and prepayments', 'current_asset'],
            ['RPR', 'Amounts due from related parties', 'current_asset'],
            ['TAXREC', 'Tax recoverable', 'current_asset'],
            ['FD', 'Fixed deposits with licensed banks', 'current_asset'],
            ['CASH', 'Cash and bank balances', 'current_asset'],
            ['SC', 'Share capital', 'equity'],
            ['RES', 'Other reserves', 'equity'],
            ['RE', 'Retained earnings / (accumulated losses)', 'equity'],
            ['BORR-NC', 'Borrowings (non-current)', 'non_current_liability'],
            ['LEASE-NC', 'Lease liabilities (non-current)', 'non_current_liability'],
            ['DTL', 'Deferred tax liabilities', 'non_current_liability'],
            ['TP', 'Trade payables', 'current_liability'],
            ['OP', 'Other payables and accruals', 'current_liability'],
            ['RPP', 'Amounts due to related parties and directors', 'current_liability'],
            ['BORR-C', 'Borrowings (current)', 'current_liability'],
            ['LEASE-C', 'Lease liabilities (current)', 'current_liability'],
            ['TAXPAY', 'Tax payable', 'current_liability'],
            ['REV', 'Revenue', 'revenue'],
            ['COS', 'Cost of sales', 'cost_of_sales'],
            ['OI', 'Other income', 'other_income'],
            ['ADMIN', 'Administrative expenses', 'expense'],
            ['SELL', 'Selling and distribution expenses', 'expense'],
            ['OPEX', 'Other operating expenses', 'expense'],
            ['FIN', 'Finance costs', 'finance_cost'],
            ['TAX', 'Income tax expense', 'tax_expense'],
        ];
        foreach ($items as $i => [$code, $name, $class]) {
            $template->items()->create(['code' => $code, 'name' => $name, 'classification' => $class, 'sort_order' => ($i + 1) * 10]);
        }
    }

    /** Strata bodies (JMB / MC): maintenance fund and sinking fund presented separately. */
    private function strata(): void
    {
        $template = FsliTemplate::firstOrCreate(['code' => 'strata'], [
            'name' => 'Strata bodies (JMB / MC)',
            'description' => 'Maintenance fund and sinking fund captions for joint management bodies and management corporations; adapt per engagement.',
            'is_default' => false,
        ]);
        if ($template->items()->exists()) {
            return;
        }
        $items = [
            ['PPE', 'Property, plant and equipment', 'non_current_asset'],
            ['MCR', 'Maintenance charges and sinking fund receivable', 'current_asset'],
            ['OR', 'Other receivables, deposits and prepayments', 'current_asset'],
            ['FD', 'Fixed deposits with licensed banks', 'current_asset'],
            ['CASH', 'Cash and bank balances — maintenance fund', 'current_asset'],
            ['CASH-SF', 'Cash and bank balances — sinking fund', 'current_asset'],
            ['MF', 'Maintenance fund — accumulated surplus', 'equity'],
            ['SF', 'Sinking fund', 'equity'],
            ['OP', 'Payables and accruals', 'current_liability'],
            ['DEP', 'Deposits and advance collections', 'current_liability'],
            ['MC-INC', 'Maintenance charges', 'revenue'],
            ['SF-INC', 'Sinking fund contributions', 'revenue'],
            ['OI', 'Other income', 'other_income'],
            ['SEC', 'Security services', 'expense'],
            ['CLEAN', 'Cleaning and landscaping', 'expense'],
            ['UTIL', 'Utilities', 'expense'],
            ['RM', 'Repairs and maintenance', 'expense'],
            ['ADMIN', 'Management and administrative expenses', 'expense'],
            ['TAX', 'Income tax expense', 'tax_expense'],
        ];
        foreach ($items as $i => [$code, $name, $class]) {
            $template->items()->create(['code' => $code, 'name' => $name, 'classification' => $class, 'sort_order' => ($i + 1) * 10]);
        }
    }
}
