<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\DocumentVersion;
use App\Services\Documents\Scanning\MalwareScanner;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Scans an uploaded version in the background. Carries only ids; restores the tenant context
 * (and PostgreSQL RLS variable) for its firm before touching data.
 */
class ScanDocumentVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public int $firmId, public int $versionId)
    {
        $this->onQueue('documents');
    }

    public function handle(TenantContext $tenant, MalwareScanner $scanner, ActivityLogger $logger): void
    {
        $tenant->runForFirm($this->firmId, function () use ($scanner, $logger) {
            $version = DocumentVersion::with('document')->find($this->versionId);
            if (! $version || $version->scan_status !== ScanStatus::Pending) {
                return;
            }

            $disk = Storage::disk($version->disk);
            $local = tempnam(sys_get_temp_dir(), 'scan');
            try {
                file_put_contents($local, $disk->readStream($version->path));
                if (hash_file('sha256', $local) !== $version->sha256) {
                    $result = \App\Services\Documents\Scanning\ScanResult::infected('Stored file does not match recorded SHA-256 hash');
                } else {
                    $result = $scanner->scan($local);
                }
            } finally {
                @unlink($local);
            }

            if ($result->status === 'failed' && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }

            $version->scan_status = ScanStatus::from($result->status);
            $version->scan_engine = $scanner->name();
            $version->scan_detail = $result->detail;
            $version->scanned_at = now();
            $version->save();

            if ($result->status === 'infected') {
                $logger->log('document.blocked', "Upload {$version->original_name} blocked by malware scan: {$result->detail}", $version->document, ['version' => $version->version_no], null, $this->firmId, 'system');
            }
        });
    }
}
