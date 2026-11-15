<?php

namespace App\Services\Ledger;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\TrialBalance\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Source files for GL and ageing imports. A file is either uploaded by the team (stored as a
 * hashed, scanned engagement document like the TB) or chosen from documents already on the
 * engagement — e.g. a listing the client uploaded through the portal. Rows are always re-read
 * from the stored document, so what is imported is exactly the evidence on file.
 */
class LedgerFiles
{
    public function __construct(private DocumentService $documents, private SpreadsheetReader $reader) {}

    public function store(Engagement $engagement, ?UploadedFile $file, ?string $documentUuid, string $title, User $actor): Document
    {
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, ['csv', 'xlsx', 'txt'], true)) {
                throw ValidationException::withMessages(['file' => 'Upload a CSV or XLSX file.']);
            }

            return $this->documents->upload($engagement, $file, $title, DocumentCategory::ClientProvided, 'Source file for import.', $actor);
        }
        $doc = $documentUuid ? $engagement->documents()->where('uuid', $documentUuid)->with('currentVersion')->first() : null;
        if (! $doc) {
            throw ValidationException::withMessages(['file' => 'Upload a file or choose a document already on the engagement.']);
        }
        if (! in_array(strtolower(pathinfo($doc->currentVersion->original_name ?? '', PATHINFO_EXTENSION)), ['csv', 'xlsx', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => 'That document is not a CSV or XLSX file.']);
        }

        return $doc;
    }

    /** Rows of a freshly uploaded file (before its scan finishes), for the preview only. */
    public function uploadedRows(UploadedFile $file, int $maxRows): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return $this->reader->read($file->getRealPath(), $ext === 'txt' ? 'csv' : $ext, $maxRows, 500, 'the file');
    }

    /** @return list<list<string>> */
    public function rows(Document $document, int $maxRows): array
    {
        $v = $document->currentVersion()->first();
        if (! $v || ! $v->isDownloadable()) {
            throw ValidationException::withMessages(['file' => 'The file has not passed the malware scan yet. Try again in a moment.']);
        }
        $ext = strtolower(pathinfo($v->original_name ?? '', PATHINFO_EXTENSION));
        $tmp = tempnam(sys_get_temp_dir(), 'ldg');
        try {
            file_put_contents($tmp, Storage::disk($v->disk)->get($v->path));

            return $this->reader->read($tmp, $ext === 'txt' ? 'csv' : $ext, $maxRows, 500, 'the file');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Finds the header row (within the first 20 rows) that matches the most known headings and
     * maps each field to a column. Exact matches win over partial matches.
     *
     * @param  array<string, list<string>>  $synonyms  field => headings, most specific first
     * @return array{0: int, 1: array<string, int>}  1-based header row, field => 0-based column
     */
    public static function guessHeader(array $rows, array $synonyms): array
    {
        $best = [1, [], -1];
        foreach (array_slice($rows, 0, 20, true) as $i => $row) {
            $lower = array_map(fn ($c) => self::norm((string) $c), $row);
            $map = [];
            foreach ($synonyms as $field => $names) {
                foreach ([true, false] as $exact) {
                    foreach ($names as $n) {
                        foreach ($lower as $col => $h) {
                            if ($h === '' || in_array($col, $map, true)) {
                                continue;
                            }
                            if ($exact ? $h === $n : (strlen($n) > 3 && str_contains($h, $n))) {
                                $map[$field] = $col;
                                continue 4;
                            }
                        }
                    }
                }
            }
            if (count($map) > $best[2]) {
                $best = [$i + 1, $map, count($map)];
            }
        }

        return [$best[0], $best[1]];
    }

    public static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['.', '_', ':'], ' ', mb_strtolower($s))));
    }

    /** Day-first dates as used in Malaysia, ISO dates and Excel serial numbers. */
    public static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v)) { // Excel serial date
            $days = (int) floor((float) $v);

            return $days > 20000 && $days < 80000 ? (new \DateTimeImmutable('1899-12-30'))->modify("+{$days} days")->format('Y-m-d') : null;
        }
        $v = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s*[ap]m)?$/i', '', $v); // drop a time part
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'd-M-Y', 'd M Y', 'd-M-y', 'd M y', 'Y/m/d'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $v);
            $err = \DateTimeImmutable::getLastErrors();
            if ($d && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                $y = (int) $d->format('Y');
                if ($y >= 1990 && $y <= 2100) {
                    return $d->format('Y-m-d');
                }
            }
        }

        return null;
    }
}
