<?php

namespace App\Services\Documents\Scanning;

/**
 * DEVELOPMENT ONLY. Blocks the EICAR test signature, executable binaries and embedded scripts.
 * It is not antivirus; production must use the clamd driver (docs/SECURITY.md).
 */
class BasicSignatureScanner implements MalwareScanner
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function name(): string
    {
        return 'basic-signature';
    }

    public function scan(string $path): ScanResult
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return ScanResult::failed('File could not be read for scanning.');
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);

        if (str_contains((string) file_get_contents($path, false, null, 0, 1024 * 1024), self::EICAR)) {
            return ScanResult::infected('EICAR test signature');
        }
        foreach (["MZ" => 'Windows executable', "\x7FELF" => 'ELF executable', "\xCA\xFE\xBA\xBE" => 'Mach-O binary', "#!" => 'Script with interpreter line'] as $magic => $label) {
            if (str_starts_with($head, $magic)) {
                return ScanResult::infected($label);
            }
        }
        if (preg_match('/<\?php|<script\b/i', $head)) {
            return ScanResult::infected('Embedded script content');
        }

        return ScanResult::clean();
    }
}
