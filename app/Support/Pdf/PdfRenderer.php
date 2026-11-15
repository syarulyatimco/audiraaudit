<?php

namespace App\Support\Pdf;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Renders self-contained HTML to PDF with headless Chrome / Chromium (ADR-046). The HTML must
 * carry its own CSS; network access from the browser is disabled (dead proxy), so nothing in a
 * document can make the renderer fetch a remote resource.
 */
class PdfRenderer
{
    public function binary(): ?string
    {
        if ($configured = config('audira.pdf.chrome')) {
            return is_executable($configured) ? $configured : null;
        }
        $finder = new ExecutableFinder;
        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome'] as $name) {
            if ($path = $finder->find($name)) {
                return $path;
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    public function render(string $html): string
    {
        $bin = $this->binary() ?? throw new RuntimeException('PDF output needs Google Chrome or Chromium on the server (set AUDIRA_PDF_CHROME).');
        $dir = sys_get_temp_dir().'/audira-pdf-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $in = $dir.'/document.html';
        $out = $dir.'/document.pdf';
        file_put_contents($in, $html);

        $args = [$bin, '--headless=new', '--disable-gpu', '--no-pdf-header-footer', '--disable-extensions',
            '--disable-background-networking', '--proxy-server=127.0.0.1:9', '--proxy-bypass-list=<-loopback>',
            '--user-data-dir='.$dir.'/profile', '--print-to-pdf='.$out];
        if (config('audira.pdf.no_sandbox')) {
            $args[] = '--no-sandbox';
        }
        $args[] = 'file://'.$in;

        try {
            $process = new Process($args, $dir, ['HOME' => $dir]);
            $process->setTimeout((int) config('audira.pdf.timeout', 60));
            $process->run();
            if (! is_file($out) || filesize($out) === 0) {
                throw new RuntimeException('The PDF could not be produced: '.trim(substr($process->getErrorOutput(), -500)));
            }

            return (string) file_get_contents($out);
        } finally {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
        }
    }
}
