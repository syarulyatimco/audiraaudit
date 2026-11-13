<?php

namespace App\Services\Reporting;

use App\Enums\FsliClassification as C;
use App\Models\Engagement;
use App\Services\TrialBalance\LeadScheduleService;

/**
 * Primary statements drafted from the ADJUSTED trial balance and the engagement's FSLI mapping:
 * statement of financial position and statement of profit or loss, with prior year. Notes and
 * framework-specific presentation (MFRS / MPERS / MPSAS) are prepared by the team; this is the
 * figures basis the report refers to and is snapshotted at sign-off.
 */
class FinancialStatements
{
    public function __construct(private LeadScheduleService $schedules) {}

    /** @return array<string, mixed> */
    public function build(Engagement $engagement): array
    {
        $data = $this->schedules->build($engagement);
        $lines = collect($data['fslis'])->map(fn ($s) => [
            'code' => $s['fsli']->code, 'name' => $s['fsli']->name, 'classification' => $s['fsli']->classification->value,
            'cy' => (int) $s['cy_adj'], 'py' => (int) $s['py'],
        ])->filter(fn ($l) => $l['cy'] !== 0 || $l['py'] !== 0)->values();

        $section = fn (array $classes) => $lines->whereIn('classification', array_map(fn ($c) => $c->value, $classes))->values()->all();
        $sum = fn (array $classes, string $k) => (int) $lines->whereIn('classification', array_map(fn ($c) => $c->value, $classes))->sum($k);

        $sofp = [
            ['label' => 'Non-current assets', 'lines' => $section([C::NonCurrentAsset]), 'cy' => $sum([C::NonCurrentAsset], 'cy'), 'py' => $sum([C::NonCurrentAsset], 'py')],
            ['label' => 'Current assets', 'lines' => $section([C::CurrentAsset]), 'cy' => $sum([C::CurrentAsset], 'cy'), 'py' => $sum([C::CurrentAsset], 'py')],
            ['label' => 'Equity', 'lines' => $section([C::Equity]), 'cy' => $sum([C::Equity], 'cy'), 'py' => $sum([C::Equity], 'py')],
            ['label' => 'Non-current liabilities', 'lines' => $section([C::NonCurrentLiability]), 'cy' => $sum([C::NonCurrentLiability], 'cy'), 'py' => $sum([C::NonCurrentLiability], 'py')],
            ['label' => 'Current liabilities', 'lines' => $section([C::CurrentLiability]), 'cy' => $sum([C::CurrentLiability], 'cy'), 'py' => $sum([C::CurrentLiability], 'py')],
        ];
        $sopl = [
            ['label' => 'Revenue', 'lines' => $section([C::Revenue]), 'sign' => 1],
            ['label' => 'Cost of sales', 'lines' => $section([C::CostOfSales]), 'sign' => -1],
            ['label' => 'Other income', 'lines' => $section([C::OtherIncome]), 'sign' => 1],
            ['label' => 'Expenses', 'lines' => $section([C::Expense]), 'sign' => -1],
            ['label' => 'Finance costs', 'lines' => $section([C::FinanceCost]), 'sign' => -1],
            ['label' => 'Tax expense', 'lines' => $section([C::TaxExpense]), 'sign' => -1],
        ];

        return [
            'sofp' => $sofp, 'sopl' => $sopl,
            'cy' => $data['cy'], 'py' => $data['py'],
            'checks' => $data['checks'],
            'balanced' => $data['checks']['tb_balances'] && $data['checks']['sofp_difference'] === 0 && $data['checks']['unmapped_count'] === 0,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
