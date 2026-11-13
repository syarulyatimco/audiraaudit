<?php

namespace App\Services\TrialBalance;

use App\Enums\DocumentCategory;
use App\Enums\TbImportStatus;
use App\Enums\TbPeriod;
use App\Models\Engagement;
use App\Models\TbImport;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Support\Audit\ActivityLogger;
use App\Support\EngagementGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Two-step import: (1) upload → file kept as hashed evidence, rows parsed, columns guessed;
 * (2) confirm column mapping → strict validation → lines written (immutable) and the import
 * becomes the engagement's active trial balance for that period (previous one superseded).
 */
class TrialBalanceImportService
{
    public function __construct(
        private SpreadsheetReader $reader,
        private DocumentService $documents,
        private FsliService $fslis,
        private LeadPaperSync $leadPapers,
        private ActivityLogger $logger,
    ) {}

    public function upload(Engagement $engagement, UploadedFile $file, TbPeriod $period, User $actor): TbImport
    {
        EngagementGuard::assertEditable($engagement);
        $rows = $this->reader->read($file->getRealPath(), $file->getClientOriginalExtension(), config('audira.trial_balance.max_rows'));
        if (count(array_filter($rows, fn ($r) => implode('', $r) !== '')) < 2) {
            throw ValidationException::withMessages(['file' => 'The file contains no trial balance rows.']);
        }

        $label = $period === TbPeriod::Current ? 'current year' : 'prior year';
        $document = $this->documents->upload($engagement, $file, "Trial balance — {$label} (as imported)", DocumentCategory::ClientProvided,
            'Source file for trial balance import.', $actor);

        $import = DB::transaction(function () use ($engagement, $period, $document, $rows, $actor) {
            $import = $engagement->tbImports()->create([
                'period' => $period, 'document_id' => $document->id, 'raw_rows' => $rows, 'imported_by' => $actor->id,
            ]);
            [$headerRow, $map] = $this->guessColumns($rows);
            $import->forceFill(['header_row' => $headerRow, 'column_map' => $map])->save();
            $this->logger->log('tb.uploaded', "Trial balance file uploaded ({$period->label()})", $engagement, ['import' => $import->uuid, 'rows' => count($rows)], $actor);

            return $import;
        });

        return $import;
    }

    /**
     * @param  array{header_row: int, code: int, name: int, mode: string, debit?: ?int, credit?: ?int, balance?: ?int}  $map  0-based columns, 1-based header row
     */
    public function activate(TbImport $import, array $map, User $actor): TbImport
    {
        $engagement = $import->engagement;
        EngagementGuard::assertEditable($engagement);
        if ($import->status !== TbImportStatus::Pending) {
            throw ValidationException::withMessages(['import' => 'This import has already been processed.']);
        }

        [$lines, $warnings, $errors] = $this->buildLines($import->raw_rows, $map);
        if ($errors) {
            $import->forceFill(['column_map' => $map])->save();
            throw ValidationException::withMessages(['import' => array_slice($errors, 0, 25)]);
        }

        $debit = array_sum(array_column($lines, 'debit_sen'));
        $credit = array_sum(array_column($lines, 'credit_sen'));

        return DB::transaction(function () use ($import, $engagement, $map, $lines, $warnings, $debit, $credit, $actor) {
            $engagement->tbImports()->where('period', $import->period->value)->where('status', TbImportStatus::Active->value)
                ->update(['status' => TbImportStatus::Superseded->value]);

            foreach (array_chunk($lines, 500) as $chunk) {
                $import->lines()->createMany($chunk);
            }
            $import->forceFill([
                'column_map' => $map, 'header_row' => $map['header_row'], 'status' => TbImportStatus::Active,
                'row_count' => count($lines), 'total_debit_sen' => $debit, 'total_credit_sen' => $credit,
                'warnings' => $warnings ?: null, 'activated_at' => now(),
            ])->save();

            $this->fslis->ensureInitialised($engagement, $actor);
            $this->logger->log('tb.activated', "Trial balance activated ({$import->period->label()}): ".count($lines).' accounts, totals '.AmountParser::format($debit), $engagement,
                ['import' => $import->uuid, 'debit_sen' => $debit, 'credit_sen' => $credit, 'warnings' => count($warnings)], $actor);
            $this->leadPapers->refresh($engagement, $actor, 'Trial balance re-imported');

            return $import;
        });
    }

    public function discard(TbImport $import, User $actor): void
    {
        if ($import->status !== TbImportStatus::Pending) {
            throw ValidationException::withMessages(['import' => 'Only pending imports can be discarded.']);
        }
        DB::transaction(function () use ($import, $actor) {
            $import->forceFill(['status' => TbImportStatus::Discarded])->save();
            $this->logger->log('tb.discarded', 'Pending trial balance import discarded', $import->engagement, ['import' => $import->uuid], $actor);
        });
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: list<string>}
     */
    public function buildLines(array $rows, array $map): array
    {
        $errors = [];
        $warnings = [];
        $lines = [];
        $seen = [];

        $mode = $map['mode'] ?? 'debit_credit';
        $need = ['code', 'name', ...($mode === 'balance' ? ['balance'] : ['debit', 'credit'])];
        foreach ($need as $k) {
            if (! isset($map[$k]) || $map[$k] === '' || $map[$k] === null) {
                return [[], [], ["Select the {$k} column."]];
            }
        }
        $headerRow = max(1, (int) ($map['header_row'] ?? 1));

        foreach ($rows as $i => $row) {
            $rowNo = $i + 1;
            if ($rowNo <= $headerRow || implode('', $row) === '') {
                continue;
            }
            $code = trim((string) ($row[(int) $map['code']] ?? ''));
            $name = trim((string) ($row[(int) $map['name']] ?? ''));

            try {
                if ($mode === 'balance') {
                    $bal = AmountParser::toSen($row[(int) $map['balance']] ?? '');
                    $dr = max($bal, 0);
                    $cr = max(-$bal, 0);
                } else {
                    $dr = AmountParser::toSen($row[(int) $map['debit']] ?? '');
                    $cr = AmountParser::toSen($row[(int) $map['credit']] ?? '');
                    if ($dr < 0 || $cr < 0) {
                        // A negative in a debit/credit column means the other side.
                        [$dr, $cr] = [max($dr, 0) + max(-$cr, 0), max($cr, 0) + max(-$dr, 0)];
                    }
                }
            } catch (InvalidArgumentException $e) {
                $errors[] = "Row {$rowNo}: ".$e->getMessage().'.';

                continue;
            }

            if ($code === '') {
                if ($dr !== 0 || $cr !== 0) {
                    $warnings[] = "Row {$rowNo} skipped: no account code (looks like a subtotal: \"".mb_substr($name, 0, 40).'").';
                }

                continue;
            }
            if (mb_strlen($code) > 50) {
                $errors[] = "Row {$rowNo}: account code is longer than 50 characters.";

                continue;
            }
            if (isset($seen[mb_strtolower($code)])) {
                $errors[] = "Row {$rowNo}: account code {$code} also appears on row {$seen[mb_strtolower($code)]}.";

                continue;
            }
            $seen[mb_strtolower($code)] = $rowNo;
            if ($name === '') {
                $warnings[] = "Row {$rowNo}: account {$code} has no name.";
            }
            if ($dr > 0 && $cr > 0) {
                $warnings[] = "Row {$rowNo}: account {$code} has both debit and credit amounts; they were netted.";
            }

            $net = $dr - $cr;
            $lines[] = [
                'row_no' => $rowNo, 'account_code' => $code, 'account_name' => $name ?: $code,
                'debit_sen' => max($net, 0), 'credit_sen' => max(-$net, 0), 'balance_sen' => $net,
            ];
        }

        if (! $lines && ! $errors) {
            $errors[] = 'No accounts were found below the header row.';
        }
        if (! $errors) {
            $sum = array_sum(array_column($lines, 'balance_sen'));
            if ($sum !== 0) {
                $errors[] = 'The trial balance does not balance: debits exceed credits by '.AmountParser::format($sum, false).'. Check for missing accounts or subtotal rows with codes.';
            }
        }

        return [$lines, $warnings, $errors];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function guessColumns(array $rows): array
    {
        foreach (array_slice($rows, 0, 25, true) as $i => $row) {
            $map = ['header_row' => $i + 1, 'mode' => 'debit_credit'];
            foreach ($row as $c => $cell) {
                $h = mb_strtolower($cell);
                if (! isset($map['code']) && preg_match('/\b(code|acc(oun)?t\.? ?(no|code)|a\/c( no)?|gl)\b/', $h)) {
                    $map['code'] = $c;
                } elseif (! isset($map['name']) && preg_match('/name|description|particular|account/', $h)) {
                    $map['name'] = $c;
                } elseif (! isset($map['debit']) && preg_match('/^\s*(debit|dr)\b/', $h)) {
                    $map['debit'] = $c;
                } elseif (! isset($map['credit']) && preg_match('/^\s*(credit|cr)\b/', $h)) {
                    $map['credit'] = $c;
                } elseif (! isset($map['balance']) && preg_match('/balance|amount|net/', $h)) {
                    $map['balance'] = $c;
                }
            }
            if (isset($map['code'], $map['name']) && (isset($map['debit'], $map['credit']) || isset($map['balance']))) {
                if (! isset($map['debit']) || ! isset($map['credit'])) {
                    $map['mode'] = 'balance';
                }

                return [$i + 1, $map];
            }
        }

        return [1, ['header_row' => 1, 'mode' => 'debit_credit', 'code' => 0, 'name' => 1, 'debit' => 2, 'credit' => 3]];
    }
}
