<?php

namespace App\Services\Documents\Scanning;

/** ClamAV daemon (clamd) INSTREAM scanner over TCP. */
class ClamdScanner implements MalwareScanner
{
    public function __construct(private string $host, private int $port, private int $timeout) {}

    public function name(): string
    {
        return 'clamd';
    }

    public function scan(string $path): ScanResult
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (! $socket) {
            return ScanResult::failed("clamd unreachable: {$errstr}");
        }
        stream_set_timeout($socket, $this->timeout);
        $file = fopen($path, 'rb');
        fwrite($socket, "zINSTREAM\0");
        while (! feof($file)) {
            $chunk = fread($file, 8192);
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($socket, pack('N', 0));
        fclose($file);
        $reply = trim((string) fgets($socket), "\0\r\n ");
        fclose($socket);

        if (str_ends_with($reply, 'OK')) {
            return ScanResult::clean();
        }
        if (str_ends_with($reply, 'FOUND')) {
            return ScanResult::infected(trim(str_replace(['stream:', 'FOUND'], '', $reply)));
        }

        return ScanResult::failed('Unexpected clamd reply: '.substr($reply, 0, 120));
    }
}
