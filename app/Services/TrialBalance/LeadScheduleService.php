<?php

namespace App\Services\TrialBalance;

use App\Enums\AdjustmentStatus;
use App\Enums\FsliClassification;
use App\Enums\TbImportStatus;
use App\Models\Engagement;
use App\Models\EngagementFsli;
use Illuminate\Support\Collection;

/**
 * Builds the account register (union of current and prior active TBs), lead schedules per FSLI
 * and the draft statements. Amounts are signed sen (debit positive) unless stated otherwise;
 * "natural" values are flipped for credit-normal FSLIs so they read as positive.
 */
class LeadScheduleService
{
    /**
     * @return Collection<string, array{code: string, name: string, cy: int, py: int, adj: int, cy_adj: int, in_cy: bool, in_py: bool, fsli_id: ?int, source: ?string}>
     */
    public function accounts(Engagement $engagement): Collection
    {
        $imports = $engagement->tbImports()->where('status', TbImportStatus::Active->value)->get()->keyBy(fn ($i) => $i->period->value);
        $accounts = collect();

        foreach (['prior' => 'py', 'current' => 'cy'] as $period => $key) {
            if (! $import = $imports->get($period)) {
                continue;
            }
            foreach ($import->lines()->get(['account_code', 'account_name', 'balance_sen']) as $line) {
                $row = $accounts->get($line->account_code, ['code' => $line->account_code, 'name' => $line->account_name, 'cy' => 0, 'py' => 0, 'adj' => 0, 'in_cy' => false, 'in_py' => false]);
                $row[$key] = $line->balance_sen;
                $row['in_'.$key] = true;
                if ($key === 'cy') {
                    $row['name'] = $line->account_name;
                }
                $accounts->put($line->account_code, $row);
            }
        }

        $adjusted = $engagement->adjustments()->where('status', AdjustmentStatus::Posted->value)
            ->join('adjustment_lines', 'adjustment_lines.adjustment_id', '=', 'adjustments.id')
            ->groupBy('adjustment_lines.account_code')
            ->selectRaw('adjustment_lines.account_code as code, sum(adjustment_lines.debit_sen - adjustment_lines.credit_sen) as net')
            ->pluck('net', 'code');

        $mappings = $engagement->accountMappings()->get()->keyBy('account_code');

        return $accounts->map(function ($row) use ($adjusted, $mappings) {
            $row['adj'] = (int) ($adjusted[$row['code']] ?? 0);
            $row['cy_adj'] = $row['cy'] + $row['adj'];
            $m = $mappings->get($row['code']);
            $row['fsli_id'] = $m?->engagement_fsli_id;
            $row['source'] = $m?->source?->value;

            return $row;
        })->sortKeys(SORT_NATURAL);
    }

    /**
     * @return array{fslis: Collection, unmapped: Collection, sofp: array, pl: array, checks: array, has_current: bool, has_prior: bool}
     */
    public function build(Engagement $engagement): array
    {
        $accounts = $this->accounts($engagement);
        $fslis = $engagement->fslis()->with('workingPaper')->get();

        $schedules = $fslis->map(function (EngagementFsli $f) use ($accounts) {
            $rows = $accounts->where('fsli_id', $f->id)->values();
            $s = $f->classification->sign();

            return [
                'fsli' => $f,
                'accounts' => $rows,
                'py' => $s * $rows->sum('py'),
                'cy' => $s * $rows->sum('cy'),
                'adj' => $s * $rows->sum('adj'),
                'cy_adj' => $s * $rows->sum('cy_adj'),
                // Accounts on the opposite side (often legitimate: accumulated depreciation, allowances).
                'contra' => $rows->filter(fn ($r) => $s * $r['cy_adj'] < 0)->pluck('code')->all(),
                // The FSLI as a whole is on the wrong side — needs reclassification or presentation review.
                'opposite' => $s * $rows->sum('cy_adj') < 0,
            ];
        })->keyBy(fn ($s) => $s['fsli']->id);

        $unmapped = $accounts->whereNull('fsli_id')->values();

        $sumClass = fn (array $classes, string $k) => $schedules->filter(fn ($s) => in_array($s['fsli']->classification, $classes, true))->sum($k);
        $statement = function (string $k) use ($sumClass) {
            $revenue = $sumClass([FsliClassification::Revenue], $k);
            $cos = $sumClass([FsliClassification::CostOfSales], $k);
            $oi = $sumClass([FsliClassification::OtherIncome], $k);
            $exp = $sumClass([FsliClassification::Expense], $k);
            $fin = $sumClass([FsliClassification::FinanceCost], $k);
            $tax = $sumClass([FsliClassification::TaxExpense], $k);
            $pbt = $revenue - $cos + $oi - $exp - $fin;

            return [
                'revenue' => $revenue, 'gross_profit' => $revenue - $cos, 'other_income' => $oi, 'expenses' => $cos + $exp + $fin,
                'pbt' => $pbt, 'tax' => $tax, 'profit' => $pbt - $tax,
                'assets' => $sumClass([FsliClassification::NonCurrentAsset, FsliClassification::CurrentAsset], $k),
                'liabilities' => $sumClass([FsliClassification::NonCurrentLiability, FsliClassification::CurrentLiability], $k),
                'equity' => $sumClass([FsliClassification::Equity], $k),
            ];
        };

        $cy = $statement('cy_adj');
        $py = $statement('py');
        $unmappedNet = (int) $unmapped->sum('cy_adj');

        return [
            'fslis' => $schedules,
            'unmapped' => $unmapped,
            'cy' => $cy,
            'py' => $py,
            'cy_unadjusted' => $statement('cy'),
            'checks' => [
                // Pre-closing TB: assets = liabilities + equity + current-year profit.
                'tb_balances' => (int) $accounts->sum('cy_adj') === 0,
                'sofp_difference' => $cy['assets'] - $cy['liabilities'] - $cy['equity'] - $cy['profit'],
                'unmapped_count' => $unmapped->count(),
                'unmapped_net' => $unmappedNet,
            ],
            'has_current' => $accounts->contains('in_cy', true),
            'has_prior' => $accounts->contains('in_py', true),
        ];
    }

    /** Deterministic fingerprint of an FSLI's figures, included in its lead paper's content hash. */
    public function fingerprint(EngagementFsli $fsli): array
    {
        return $this->accounts($fsli->engagement)->where('fsli_id', $fsli->id)
            ->map(fn ($r) => [$r['py'], $r['cy'], $r['adj']])->all();
    }
}
