<?php

namespace App\Services\Ledger;

use App\Models\AgeingLine;
use App\Models\AgeingReport;
use App\Models\Engagement;
use App\Models\SamplingPlan;
use App\Models\User;
use App\Services\Sampling\SamplingService;
use App\Services\TrialBalance\AmountParser;
use App\Services\TrialBalance\LeadScheduleService;
use App\Support\Audit\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aged receivables / payables listings (ADR-050). Upload → confirm columns and the age of each
 * bucket → activate (immutable lines). Each listing is reconciled to its FSLI and analysed:
 * amounts by age, balances past 90 / 180 / 365 days, balances on the wrong side, concentration,
 * and movement against the prior-year listing.
 */
class AgeingService
{
    public const MAX_ROWS = 20000;

    public const SYNONYMS = [
        'code' => ['customer code', 'debtor code', 'supplier code', 'creditor code', 'acc no', 'account no', 'account code', 'code', 'cust code', 'vendor code', 'id'],
        'name' => ['customer name', 'debtor name', 'supplier name', 'creditor name', 'company name', 'name', 'customer', 'debtor', 'supplier', 'creditor', 'vendor', 'description'],
        'total' => ['total', 'balance', 'outstanding', 'total outstanding', 'amount due', 'total due', 'amount', 'grand total'],
    ];

    public function __construct(private LedgerFiles $files, private GeneralLedgerService $gl, private ActivityLogger $logger, private LeadScheduleService $schedules) {}

    public function upload(Engagement $engagement, ?UploadedFile $file, ?string $documentUuid, array $data, User $actor): AgeingReport
    {
        $this->gl->authorize($engagement, $actor);
        $type = in_array($data['type'] ?? '', ['receivables', 'payables'], true) ? $data['type'] : throw ValidationException::withMessages(['type' => 'Choose receivables or payables.']);
        $period = ($data['period'] ?? 'current') === 'prior' ? 'prior' : 'current';
        $asAt = LedgerFiles::date((string) ($data['as_at'] ?? '')) ?? throw ValidationException::withMessages(['as_at' => 'Give the date of the listing.']);
        $label = ($type === 'receivables' ? 'Aged receivables' : 'Aged payables')." at {$asAt}";
        $document = $this->files->store($engagement, $file, $documentUuid, "{$label} (as imported)", $actor);
        $rows = $file ? $this->files->uploadedRows($file, self::MAX_ROWS) : $this->files->rows($document, self::MAX_ROWS);
        [$header, $map] = LedgerFiles::guessHeader($rows, self::SYNONYMS);
        $buckets = $this->guessBuckets($rows[$header - 1] ?? [], $map);
        $fsli = filled($data['engagement_fsli_id'] ?? null) ? $engagement->fslis()->whereKey($data['engagement_fsli_id'])->first()
            : $engagement->fslis()->where('code', $type === 'receivables' ? 'TR' : 'TP')->first();

        $report = AgeingReport::create([
            'engagement_id' => $engagement->id, 'document_id' => $document->id, 'type' => $type, 'period' => $period,
            'engagement_fsli_id' => $fsli?->id, 'as_at' => $asAt, 'header_row' => $header, 'column_map' => $map, 'buckets' => $buckets,
            'preview' => array_slice($rows, max(0, $header - 1), 21), 'imported_by' => $actor->id,
        ]);
        $this->logger->log('ageing.uploaded', "{$label} uploaded", $engagement, ['report' => $report->uuid], $actor);

        return $report;
    }

    /** Numeric-looking headings other than the code / name / total columns become buckets. */
    private function guessBuckets(array $header, array $map): array
    {
        $out = [];
        foreach ($header as $col => $h) {
            if (in_array($col, $map, true)) {
                continue;
            }
            $n = LedgerFiles::norm((string) $h);
            if ($n !== '' && preg_match('/current|not due|\d|day|month|over|above|below|within|\+|>/', $n)) {
                $out[] = ['col' => $col, 'label' => trim((string) $h), 'min_days' => $this->minDays($n, $out ? end($out)['min_days'] : -1)];
            }
        }

        return $out;
    }

    private function minDays(string $h, int $previous): int
    {
        if (preg_match('/current|not due|within credit/', $h)) {
            return 0;
        }
        $months = str_contains($h, 'month') ? 30 : 1;
        if (preg_match('/(\d+)\s*-\s*(\d+)/', $h, $m)) {
            return (int) $m[1] * $months;
        }
        if (preg_match('/(?:>|over|above|more than)\s*(\d+)/', $h, $m)) {
            return (int) $m[1] * $months + 1;
        }
        if (preg_match('/(\d+)\s*\+|(\d+)\s*(?:days?|months?)?\s*(?:and )?(?:above|over)/', $h, $m)) {
            return (int) ($m[1] ?: $m[2]) * $months;
        }
        if (preg_match('/(\d+)/', $h, $m)) {
            return (int) $m[1] * $months;
        }

        return $previous + 1;
    }

    public function activate(AgeingReport $report, array $in, User $actor): AgeingReport
    {
        $engagement = $report->engagement;
        $this->gl->authorize($engagement, $actor);
        if ($report->status !== 'pending') {
            throw ValidationException::withMessages(['report' => 'This listing has already been processed.']);
        }
        $headerRow = max(1, (int) ($in['header_row'] ?? $report->header_row));
        $col = fn (string $k) => isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null ? (int) $in[$k] : null;
        [$code, $name, $total] = [$col('code'), $col('name'), $col('total')];
        if ($code === null && $name === null) {
            throw ValidationException::withMessages(['map' => 'Choose the customer / supplier code or name column.']);
        }
        $buckets = [];
        foreach ((array) ($in['buckets'] ?? []) as $b) {
            if (! empty($b['use']) && isset($b['col']) && $b['col'] !== '') {
                $buckets[] = ['col' => (int) $b['col'], 'label' => mb_substr(trim((string) ($b['label'] ?? '')), 0, 40) ?: 'Bucket', 'min_days' => max(0, (int) ($b['min_days'] ?? 0))];
            }
        }
        usort($buckets, fn ($a, $b) => $a['min_days'] <=> $b['min_days']);
        if (! $buckets) {
            throw ValidationException::withMessages(['map' => 'Tick at least one ageing column.']);
        }
        if (count(array_unique(array_column($buckets, 'min_days'))) !== count($buckets)) {
            throw ValidationException::withMessages(['map' => 'Each ageing column needs a different "from (days)" value.']);
        }

        $rows = array_slice($this->files->rows($report->document, self::MAX_ROWS), $headerRow);
        $lines = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $n = $headerRow + $i + 1;
            $partyCode = $code !== null ? trim((string) ($r[$code] ?? '')) : '';
            $partyName = $name !== null ? trim((string) ($r[$name] ?? '')) : '';
            if ($partyCode === '' && $partyName === '') {
                continue;
            }
            if (preg_match('/^\s*(grand )?total\b|^\s*sub\s*-?total/i', $partyCode.' '.$partyName)) {
                continue; // report totals
            }
            try {
                $amounts = array_map(fn ($b) => AmountParser::toSen(trim((string) ($r[$b['col']] ?? '')) ?: '0'), $buckets);
                $lineTotal = $total !== null ? AmountParser::toSen(trim((string) ($r[$total] ?? '')) ?: '0') : array_sum($amounts);
            } catch (Throwable) {
                $errors[] = "Row {$n}: invalid amount.";

                continue;
            }
            if ($lineTotal !== array_sum($amounts)) {
                $errors[] = "Row {$n} ({$partyName}): the ageing columns add up to ".AmountParser::format(array_sum($amounts)).', not the total '.AmountParser::format($lineTotal).'.';

                continue;
            }
            $key = mb_substr($partyCode !== '' ? $partyCode : $partyName, 0, 60);
            if (isset($seen[$key])) {
                $key = mb_substr($key, 0, 54).'#'.$n;
            }
            $seen[$key] = true;
            $lines[] = ['party_code' => $key, 'party_name' => mb_substr($partyName, 0, 200) ?: null, 'total_sen' => $lineTotal, 'bucket_sen' => json_encode($amounts)];
            if (count($errors) > 50) {
                break;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['map' => array_slice($errors, 0, 10)]);
        }
        if (! $lines) {
            throw ValidationException::withMessages(['map' => 'No customer or supplier rows were found with these columns.']);
        }

        DB::transaction(function () use ($report, $lines, $buckets, $headerRow, $code, $name, $total, $engagement, $actor) {
            $engagement->ageingReports()->where('type', $report->type)->where('period', $report->period)->where('status', 'active')->update(['status' => 'superseded']);
            foreach (array_chunk($lines, 1000, true) as $chunk) {
                AgeingLine::insert(array_map(fn ($l, $k) => $l + ['firm_id' => $report->firm_id, 'ageing_report_id' => $report->id, 'seq' => $k + 1], $chunk, array_keys($chunk)));
            }
            $report->forceFill([
                'status' => 'active', 'header_row' => $headerRow, 'column_map' => array_filter(['code' => $code, 'name' => $name, 'total' => $total], fn ($c) => $c !== null),
                'buckets' => array_map(fn ($b) => ['label' => $b['label'], 'min_days' => $b['min_days']], $buckets),
                'line_count' => count($lines), 'total_sen' => array_sum(array_column($lines, 'total_sen')), 'activated_by' => $actor->id, 'activated_at' => now(),
            ])->save();
            $this->logger->log('ageing.activated', $report->label().' imported: '.count($lines).' balances', $report, ['total' => $report->total_sen], $actor);
        });

        return $report->fresh();
    }

    public function discard(AgeingReport $report, User $actor): void
    {
        $this->gl->authorize($report->engagement, $actor);
        if ($report->status !== 'pending') {
            throw ValidationException::withMessages(['report' => 'Only a listing that has not been activated can be discarded.']);
        }
        $report->forceFill(['status' => 'discarded'])->save();
    }

    public function analysis(AgeingReport $report): array
    {
        $lines = $report->lines()->get();
        $buckets = $report->buckets;
        $total = (int) $lines->sum('total_sen');
        $byBucket = array_map(fn ($i) => (int) $lines->sum(fn ($l) => $l->bucket_sen[$i] ?? 0), array_keys($buckets));
        $over = fn (int $days) => array_sum(array_map(fn ($b, $i) => $b['min_days'] >= $days ? $byBucket[$i] : 0, $buckets, array_keys($buckets)));
        $top = $lines->sortByDesc('total_sen')->take(10)->values();

        // Reconciliation to the FSLI: unadjusted TB (client's figures) for the period, plus adjusted.
        $recon = null;
        if ($report->fsli) {
            $s = collect($this->schedules->build($report->engagement)['fslis'])->first(fn ($x) => $x['fsli']->id === $report->engagement_fsli_id);
            if ($s) {
                $tb = $report->period === 'prior' ? (int) $s['py'] : (int) $s['cy'];
                $recon = ['fsli' => $report->fsli->code.' · '.$report->fsli->name, 'tb' => $tb, 'adjusted' => $report->period === 'prior' ? null : (int) $s['cy_adj'], 'difference' => $total - $tb];
            }
        }
        $prior = $report->period === 'current' ? $report->engagement->ageingReports()->where('type', $report->type)->where('period', 'prior')->where('status', 'active')->first() : null;

        return [
            'total' => $total, 'count' => $lines->count(),
            'buckets' => array_map(fn ($b, $i) => $b + ['amount' => $byBucket[$i], 'pct' => $total ? round($byBucket[$i] * 100 / $total, 1) : 0], $buckets, array_keys($buckets)),
            'over' => ['90' => $over(91), '180' => $over(181), '365' => $over(366)],
            'wrong_side' => $lines->filter(fn ($l) => $l->total_sen < 0)->sortBy('total_sen')->values(),
            'top' => $top, 'top_share' => $total ? round($top->sum('total_sen') * 100 / $total, 1) : 0,
            'recon' => $recon,
            'prior' => $prior ? ['as_at' => $prior->as_at, 'total' => (int) $prior->total_sen, 'analysis' => $this->bucketsOnly($prior)] : null,
        ];
    }

    private function bucketsOnly(AgeingReport $r): array
    {
        $lines = $r->lines()->get();

        return array_map(fn ($b, $i) => $b + ['amount' => (int) $lines->sum(fn ($l) => $l->bucket_sen[$i] ?? 0)], $r->buckets, array_keys($r->buckets));
    }

    /** Positive balances of the listing become the sampling population (e.g. for confirmations). */
    public function populationFor(SamplingPlan $plan, AgeingReport $report, User $actor): int
    {
        if ($report->engagement_id !== $plan->engagement_id || $report->status !== 'active') {
            throw ValidationException::withMessages(['population' => 'Choose an imported listing of this engagement.']);
        }
        $items = $report->lines()->where('total_sen', '>', 0)->get()->map(fn ($l) => [
            'item_ref' => $l->party_code, 'description' => $l->party_name, 'amount_sen' => (int) $l->total_sen, 'item_date' => $report->as_at->toDateString(),
        ])->all();
        app(SamplingService::class)->loadPopulation($plan, $items, $report->label(), $actor);

        return count($items);
    }
}
