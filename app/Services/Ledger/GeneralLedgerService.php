<?php

namespace App\Services\Ledger;

use App\Enums\TbImportStatus;
use App\Models\Engagement;
use App\Models\GlImport;
use App\Models\GlLine;
use App\Models\SamplingPlan;
use App\Models\User;
use App\Services\Sampling\SamplingService;
use App\Services\TrialBalance\AmountParser;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * General ledger import (ADR-048). Two steps like the TB: upload → confirm columns against a
 * preview → activate. Activation re-reads the stored source file, writes immutable lines and
 * reconciles every account to the trial balance: opening balance + GL movements = TB closing.
 */
class GeneralLedgerService
{
    public const MAX_ROWS = 200000;

    /** Headings used by common accounting exports (most specific first). */
    public const SYNONYMS = [
        'date' => ['date', 'doc date', 'trans date', 'transaction date', 'posting date', 'posted date', 'tarikh', 'gl date', 'entry date'],
        'account' => ['acc no', 'account no', 'account code', 'acc code', 'accno', 'gl code', 'gl account', 'a/c no', 'a/c code', 'account number', 'code', 'account'],
        'name' => ['account name', 'acc name', 'a/c name', 'account description', 'acc desc', 'gl name'],
        'reference' => ['doc no', 'document no', 'reference', 'ref no', 'refno', 'reference no', 'voucher no', 'journal no', 'jv no', 'doc num', 'ref', 'entry no', 'transaction no'],
        'description' => ['description', 'desp', 'particulars', 'narration', 'details', 'memo', 'remark', 'remarks', 'desc'],
        'debit' => ['debit', 'dr', 'debit amount', 'local dr', 'dr amount', 'home dr'],
        'credit' => ['credit', 'cr', 'credit amount', 'local cr', 'cr amount', 'home cr'],
        'amount' => ['amount', 'net amount', 'net', 'value', 'local amount'],
        'journal' => ['journal type', 'journal', 'source', 'doc type', 'document type', 'module', 'jnl', 'type', 'source type', 'transaction type'],
        'user' => ['created by', 'entered by', 'posted by', 'user', 'userid', 'user id', 'user name', 'operator'],
    ];

    private const OPENING = '/^\s*(opening( balance)?|balance b\/?f|b\/?f|brought forward|bal(ance)? brought forward|baki bawa)\b/i';

    public function __construct(private LedgerFiles $files, private ActivityLogger $logger) {}

    public function upload(Engagement $engagement, ?UploadedFile $file, ?string $documentUuid, User $actor): GlImport
    {
        $this->authorize($engagement, $actor);
        $document = $this->files->store($engagement, $file, $documentUuid, 'General ledger (as imported)', $actor);
        $rows = $file ? $this->files->uploadedRows($file, self::MAX_ROWS) : $this->files->rows($document, self::MAX_ROWS);
        if (count(array_filter($rows, fn ($r) => implode('', $r) !== '')) < 2) {
            throw ValidationException::withMessages(['file' => 'The file contains no ledger rows.']);
        }
        [$header, $map] = LedgerFiles::guessHeader($rows, self::SYNONYMS);
        $import = GlImport::create([
            'engagement_id' => $engagement->id, 'document_id' => $document->id,
            'header_row' => $header, 'column_map' => $map,
            'preview' => array_slice($rows, max(0, $header - 1), 26),
            'imported_by' => $actor->id,
        ]);
        $this->logger->log('gl.uploaded', 'General ledger file uploaded ('.count($rows).' rows)', $engagement, ['import' => $import->uuid], $actor);

        return $import;
    }

    /** @param array<string, int|string|null> $map field => 0-based column; header_row is 1-based */
    public function activate(GlImport $import, array $map, User $actor): GlImport
    {
        $engagement = $import->engagement;
        $this->authorize($engagement, $actor);
        if ($import->status !== 'pending') {
            throw ValidationException::withMessages(['import' => 'This import has already been processed.']);
        }
        $headerRow = max(1, (int) ($map['header_row'] ?? $import->header_row));
        $cols = collect(array_keys(self::SYNONYMS))->mapWithKeys(fn ($f) => [$f => isset($map[$f]) && $map[$f] !== '' && $map[$f] !== null ? (int) $map[$f] : null])->all();
        foreach (['date' => 'date', 'account' => 'account code'] as $f => $label) {
            if ($cols[$f] === null) {
                throw ValidationException::withMessages(['map' => "Choose the {$label} column."]);
            }
        }
        if ($cols['amount'] === null && ($cols['debit'] === null || $cols['credit'] === null)) {
            throw ValidationException::withMessages(['map' => 'Choose either the Debit and Credit columns, or a single signed Amount column.']);
        }

        $rows = array_slice($this->files->rows($import->document, self::MAX_ROWS), $headerRow);
        $lines = [];
        $errors = [];
        foreach ($rows as $i => $r) {
            $n = $headerRow + $i + 1;
            $get = fn (string $f) => $cols[$f] === null ? '' : trim((string) ($r[$cols[$f]] ?? ''));
            if (implode('', $r) === '') {
                continue;
            }
            $account = $get('account');
            $desc = $get('description');
            if ($account === '' && preg_match('/\btotal\b/i', implode(' ', $r))) {
                continue; // subtotal / grand total rows in report-style exports
            }
            try {
                if ($cols['amount'] !== null) {
                    $amt = AmountParser::toSen($get('amount') ?: '0');
                    [$dr, $cr] = $amt >= 0 ? [$amt, 0] : [0, -$amt];
                } else {
                    $dr = AmountParser::toSen($get('debit') ?: '0');
                    $cr = AmountParser::toSen($get('credit') ?: '0');
                    if ($dr < 0 || $cr < 0) {
                        [$dr, $cr] = [max(0, $dr) + max(0, -$cr), max(0, $cr) + max(0, -$dr)];
                    }
                }
            } catch (Throwable) {
                $errors[] = "Row {$n}: invalid amount.";

                continue;
            }
            $opening = (bool) preg_match(self::OPENING, $desc) || (bool) preg_match(self::OPENING, $get('reference'));
            $date = LedgerFiles::date($get('date'));
            if ($account === '') {
                $errors[] = "Row {$n}: missing account code.";

                continue;
            }
            if (! $date && ! $opening) {
                $errors[] = "Row {$n}: \"{$get('date')}\" is not a date.";

                continue;
            }
            if ($dr === 0 && $cr === 0 && ! $opening) {
                continue; // zero lines carry no posting
            }
            $lines[] = [
                'account_code' => mb_substr($account, 0, 50), 'account_name' => mb_substr($get('name'), 0, 200) ?: null,
                'posted_on' => $date ?? $engagement->period_start->toDateString(),
                'reference' => mb_substr($get('reference'), 0, 100) ?: null, 'description' => mb_substr($desc, 0, 500) ?: null,
                'debit_sen' => $dr, 'credit_sen' => $cr, 'amount_sen' => $dr - $cr,
                'journal_type' => mb_substr($get('journal'), 0, 50) ?: null, 'posted_by' => mb_substr($get('user'), 0, 100) ?: null,
                'is_opening' => $opening,
            ];
            if (count($errors) > 50) {
                break;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['map' => array_slice($errors, 0, 10)]);
        }
        if (! $lines) {
            throw ValidationException::withMessages(['map' => 'No ledger postings were found with these columns.']);
        }

        DB::transaction(function () use ($import, $lines, $headerRow, $cols, $engagement, $actor) {
            $engagement->glImports()->where('status', 'active')->update(['status' => 'superseded']);
            foreach (array_chunk($lines, 1000, true) as $chunk) {
                $insert = [];
                foreach ($chunk as $k => $l) {
                    $insert[] = $l + ['firm_id' => $import->firm_id, 'gl_import_id' => $import->id, 'seq' => $k + 1];
                }
                GlLine::insert($insert);
            }
            $movements = array_filter($lines, fn ($l) => ! $l['is_opening']);
            $dates = array_column($movements, 'posted_on');
            $import->forceFill([
                'status' => 'active', 'header_row' => $headerRow, 'column_map' => array_filter($cols, fn ($c) => $c !== null),
                'line_count' => count($lines), 'debit_sen' => array_sum(array_column($lines, 'debit_sen')), 'credit_sen' => array_sum(array_column($lines, 'credit_sen')),
                'first_date' => $dates ? min($dates) : null, 'last_date' => $dates ? max($dates) : null,
                'activated_by' => $actor->id, 'activated_at' => now(),
            ])->save();
            $import->forceFill(['reconciliation' => $this->reconcile($import)])->save();
            $r = $import->reconciliation;
            $this->logger->log('gl.activated', "General ledger imported: {$import->line_count} postings, {$r['differences']} account difference(s) with the TB", $engagement, ['import' => $import->uuid] + $r['summary'], $actor);
        });

        return $import->fresh();
    }

    public function discard(GlImport $import, User $actor): void
    {
        $this->authorize($import->engagement, $actor);
        if ($import->status !== 'pending') {
            throw ValidationException::withMessages(['import' => 'Only an import that has not been activated can be discarded.']);
        }
        $import->forceFill(['status' => 'discarded'])->save();
    }

    /**
     * Opening + movements = closing, per account. Opening comes from "balance b/f" rows in the GL
     * when present; otherwise from the prior-year TB for balance-sheet accounts (nil for P&L).
     */
    public function reconcile(GlImport $import): array
    {
        $engagement = $import->engagement;
        $gl = DB::table('gl_lines')->where('gl_import_id', $import->id)
            ->selectRaw('account_code, max(account_name) as name, sum(case when is_opening then amount_sen else 0 end) as opening, sum(case when is_opening then 0 else amount_sen end) as movement, bool_or(is_opening) as has_opening, count(*) as lines')
            ->groupBy('account_code')->get()->keyBy('account_code');
        $tb = fn (string $period) => DB::table('tb_lines')->join('tb_imports', 'tb_imports.id', '=', 'tb_lines.tb_import_id')
            ->where('tb_imports.engagement_id', $engagement->id)->where('tb_imports.status', TbImportStatus::Active->value)->where('tb_imports.period', $period)
            ->pluck('tb_lines.balance_sen', 'tb_lines.account_code');
        $cy = $tb('current');
        $py = $tb('prior');
        $classes = DB::table('account_mappings')->join('engagement_fslis', 'engagement_fslis.id', '=', 'account_mappings.engagement_fsli_id')
            ->where('account_mappings.engagement_id', $engagement->id)->pluck('engagement_fslis.classification', 'account_mappings.account_code');

        $accounts = [];
        foreach ($gl->keys()->merge($cy->keys())->unique()->sort()->values() as $code) {
            $g = $gl->get($code);
            $class = isset($classes[$code]) ? \App\Enums\FsliClassification::from($classes[$code]) : null;
            $isPl = $class?->statement() === 'pl';
            $opening = $g?->has_opening ? (int) $g->opening : ($isPl ? 0 : (int) ($py[$code] ?? 0));
            $glClosing = $opening + (int) ($g->movement ?? 0);
            $tbClosing = $cy->has($code) ? (int) $cy[$code] : null;
            $status = ! $g ? ($tbClosing ? 'missing_in_gl' : 'agreed') : ($tbClosing === null ? ($glClosing === 0 ? 'agreed' : 'missing_in_tb') : ($glClosing === $tbClosing ? 'agreed' : 'difference'));
            $accounts[] = ['code' => (string) $code, 'name' => $g->name ?? null, 'opening' => $opening, 'opening_source' => $g?->has_opening ? 'gl' : ($isPl ? 'nil (P&L)' : ($py->has($code) ? 'prior TB' : 'none')),
                'movement' => (int) ($g->movement ?? 0), 'gl_closing' => $glClosing, 'tb_closing' => $tbClosing, 'difference' => $glClosing - (int) $tbClosing, 'status' => $status, 'lines' => (int) ($g->lines ?? 0)];
        }
        usort($accounts, fn ($a, $b) => [$a['status'] === 'agreed', $a['code']] <=> [$b['status'] === 'agreed', $b['code']]);
        $diff = count(array_filter($accounts, fn ($a) => $a['status'] !== 'agreed'));

        return [
            'differences' => $diff,
            'balanced' => (int) $import->debit_sen === (int) $import->credit_sen,
            'tb_available' => $cy->isNotEmpty(),
            'summary' => ['accounts' => count($accounts), 'agreed' => count($accounts) - $diff, 'debits' => (int) $import->debit_sen, 'credits' => (int) $import->credit_sen],
            'accounts' => $accounts,
        ];
    }

    /**
     * Loads a sampling population from GL postings of the chosen accounts. Debits or credits are
     * taken at their face value; "all" uses the absolute amount of each posting.
     */
    public function populationFor(SamplingPlan $plan, array $accountCodes, string $side, User $actor): int
    {
        $engagement = $plan->engagement;
        $gl = $engagement->activeGlImport() ?? throw ValidationException::withMessages(['population' => 'Import the general ledger first.']);
        $codes = array_values(array_filter(array_map('trim', $accountCodes)));
        if (! $codes) {
            throw ValidationException::withMessages(['population' => 'Choose the accounts to sample from.']);
        }
        if (! in_array($side, ['debits', 'credits', 'all'], true)) {
            throw ValidationException::withMessages(['population' => 'Choose debits, credits or all postings.']);
        }
        $q = $gl->lines()->whereIn('account_code', $codes)->where('is_opening', false)->orderBy('seq');
        match ($side) { 'debits' => $q->where('debit_sen', '>', 0), 'credits' => $q->where('credit_sen', '>', 0), default => $q->where('amount_sen', '!=', 0) };
        $items = $q->limit(100001)->get()->map(fn (GlLine $l) => [
            'item_ref' => 'GL'.$l->seq,
            'description' => mb_substr(trim($l->account_code.' · '.($l->reference ? $l->reference.' · ' : '').$l->description), 0, 300),
            'amount_sen' => match ($side) { 'debits' => $l->debit_sen, 'credits' => $l->credit_sen, default => abs($l->amount_sen) },
            'item_date' => $l->posted_on->toDateString(),
        ])->all();
        app(SamplingService::class)->loadPopulation($plan, $items, 'General ledger: '.implode(', ', $codes)." ({$side})", $actor);

        return count($items);
    }

    public function authorize(Engagement $engagement, User $actor): void
    {
        EngagementGuard::assertEditable($engagement);
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.import_trial_balance')) {
            throw ValidationException::withMessages(['file' => 'Your role on this engagement cannot import ledgers.']);
        }
    }
}
