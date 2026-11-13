<?php

namespace App\Services\Documents;

use App\Enums\ScanStatus;
use App\Models\DocumentVersion;
use App\Services\TrialBalance\SpreadsheetReader;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Plain text from a CLEAN document version for AI analysis. Supports txt/csv, xlsx (cached values),
 * docx (document.xml) and PDF (poppler `pdftotext` when installed). Images and scanned PDFs need
 * OCR, which is not configured — the extractor says so instead of guessing.
 *
 * @return array{text: string, method: string, truncated: bool}
 */
class DocumentTextExtractor
{
    public const MAX_CHARS = 30000;

    public function extract(DocumentVersion $version): array
    {
        if ($version->scan_status !== ScanStatus::Clean) {
            return ['text' => '', 'method' => 'not scanned clean', 'truncated' => false];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'audira');
        file_put_contents($tmp, Storage::disk($version->disk)->get($version->path));
        $ext = strtolower(pathinfo($version->original_name, PATHINFO_EXTENSION));
        try {
            [$text, $method] = match ($ext) {
                'txt', 'csv' => [file_get_contents($tmp), 'plain text'],
                'xlsx' => [$this->xlsx($tmp), 'spreadsheet values'],
                'docx' => [$this->docx($tmp), 'Word document text'],
                'pdf' => $this->pdf($tmp),
                default => ['', 'unsupported type (OCR not configured)'],
            };
        } catch (Throwable) {
            [$text, $method] = ['', 'could not be read'];
        } finally {
            @unlink($tmp);
        }
        $text = trim(preg_replace("/[ \t]+/", ' ', mb_convert_encoding((string) $text, 'UTF-8', 'UTF-8')));

        return ['text' => mb_substr($text, 0, self::MAX_CHARS), 'method' => $method, 'truncated' => mb_strlen($text) > self::MAX_CHARS];
    }

    private function xlsx(string $path): string
    {
        $rows = app(SpreadsheetReader::class)->read($path, 'xlsx', 2000);

        return implode("\n", array_map(fn ($r) => implode(' | ', array_map('strval', $r)), $rows));
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return strip_tags(str_replace(['</w:p>', '<w:tab/>'], ["\n", ' '], $xml));
    }

    /** @return array{0: string, 1: string} */
    private function pdf(string $path): array
    {
        $bin = trim((string) @shell_exec('command -v pdftotext'));
        if ($bin === '') {
            return ['', 'PDF text extraction unavailable (install poppler-utils)'];
        }
        $p = new Process([$bin, '-layout', '-l', '20', $path, '-']);
        $p->setTimeout(30);
        $p->run();
        $text = $p->isSuccessful() ? $p->getOutput() : '';

        return [$text, trim($text) === '' ? 'PDF has no text layer (scanned image; OCR not configured)' : 'PDF text layer'];
    }
}
