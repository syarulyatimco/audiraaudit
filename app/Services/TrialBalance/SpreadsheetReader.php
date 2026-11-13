<?php

namespace App\Services\TrialBalance;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Reads the first worksheet of a CSV or XLSX file into a list of rows of strings.
 * The XLSX reader uses only ZipArchive + XML (no macros, no formulas evaluated: cached values only)
 * and guards against zip bombs and oversized sheets. Files are untrusted input.
 */
class SpreadsheetReader
{
    private const MAX_XML_BYTES = 60 * 1024 * 1024;

    /** @return list<list<string>> */
    public function read(string $path, string $extension, int $maxRows): array
    {
        $rows = match (strtolower($extension)) {
            'csv', 'txt' => $this->readCsv($path, $maxRows),
            'xlsx' => $this->readXlsx($path, $maxRows),
            default => throw ValidationException::withMessages(['file' => 'Upload the trial balance as CSV or XLSX.']),
        };

        // Drop trailing empty rows; normalise cells.
        $rows = array_map(fn ($r) => array_map(fn ($c) => mb_substr(trim((string) $c), 0, 255), $r), $rows);
        while ($rows && implode('', end($rows)) === '') {
            array_pop($rows);
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private function readCsv(string $path, int $maxRows): array
    {
        $content = file_get_contents($path);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $first = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($rows) >= $maxRows) {
                throw ValidationException::withMessages(['file' => "The file has more than {$maxRows} rows."]);
            }
            $rows[] = $row === [null] ? [] : $row;
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private function readXlsx(string $path, int $maxRows): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'The XLSX file could not be opened.']);
        }

        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += $zip->statIndex($i)['size'];
            }
            if ($total > self::MAX_XML_BYTES * 2) {
                throw ValidationException::withMessages(['file' => 'The workbook is too large when uncompressed.']);
            }

            $shared = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $sst = $this->xml($xml);
                foreach ($sst->si as $si) {
                    $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
                }
            }

            $sheetPath = $this->firstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false || strlen($sheetXml) > self::MAX_XML_BYTES) {
                throw ValidationException::withMessages(['file' => 'The first worksheet could not be read.']);
            }
            $sheet = $this->xml($sheetXml);

            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                $r = (int) $row['r'] ?: count($rows) + 1;
                if ($r > $maxRows) {
                    throw ValidationException::withMessages(['file' => "The worksheet has more than {$maxRows} rows."]);
                }
                $cells = [];
                foreach ($row->c as $c) {
                    $col = $this->columnIndex((string) $c['r']);
                    $type = (string) $c['t'];
                    $value = match ($type) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => (string) ($c->is->t ?? ''),
                        'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $c->v,
                    };
                    $cells[$col] = $value;
                }
                $max = $cells ? max(array_keys($cells)) : -1;
                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                // Preserve empty rows so row numbers match what the user sees in Excel.
                while (count($rows) < $r - 1) {
                    $rows[] = [];
                }
                $rows[] = $line;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $wb = $this->xml((string) $zip->getFromName('xl/workbook.xml'));
        $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheet = $wb->xpath('//m:sheets/m:sheet')[0] ?? null;
        $rid = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : null;

        $rels = $this->xml((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw ValidationException::withMessages(['file' => 'The workbook structure is not supported.']);
        }
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($doc === false) {
            throw ValidationException::withMessages(['file' => 'The workbook is corrupted.']);
        }

        return $doc;
    }

    private function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', $ref);
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
