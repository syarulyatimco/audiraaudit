<?php

namespace App\Services\Documents;

use App\Enums\DocumentCategory;
use App\Enums\WorkingPaperStatus;
use App\Jobs\ScanDocumentVersion;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Engagement;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\EngagementGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secure evidence storage: private disk, opaque object keys, SHA-256 integrity hash, immutable
 * versions, malware scan before any human (or AI) can open the file.
 */
class DocumentService
{
    public function __construct(private ActivityLogger $logger, private \App\Services\WorkingPapers\WorkingPaperService $papers) {}

    public function upload(Engagement $engagement, UploadedFile $file, string $title, DocumentCategory $category, ?string $description, User $actor): Document
    {
        EngagementGuard::assertEditable($engagement);

        $document = DB::transaction(function () use ($engagement, $title, $category, $description, $actor) {
            return $engagement->documents()->create([
                'title' => $title, 'category' => $category, 'description' => $description, 'uploaded_by' => $actor->id,
            ]);
        });

        $this->addVersion($document, $file, $actor, isNew: true);

        return $document->fresh('currentVersion');
    }

    public function addVersion(Document $document, UploadedFile $file, User $actor, bool $isNew = false): DocumentVersion
    {
        $engagement = $document->engagement;
        EngagementGuard::assertEditable($engagement);
        if ($document->archived_at) {
            throw ValidationException::withMessages(['file' => 'Archived documents cannot receive new versions.']);
        }

        $disk = config('audira.documents.disk');
        $hash = hash_file('sha256', $file->getRealPath());
        $firmUuid = $engagement->firm()->value('uuid');
        // Opaque key: never derived from the client-supplied filename.
        $path = sprintf('firms/%s/engagements/%s/%s/%s.bin', $firmUuid, $engagement->uuid, $document->uuid, Str::uuid());

        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        try {
            $version = DB::transaction(function () use ($document, $file, $actor, $disk, $path, $hash, $isNew) {
                Document::whereKey($document->id)->lockForUpdate()->first(); // serialise version numbering
                $next = (int) DocumentVersion::where('document_id', $document->id)->max('version_no') + 1;
                $version = $document->versions()->create([
                    'version_no' => $next,
                    'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                    'disk' => $disk,
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'sha256' => $hash,
                    'uploaded_by' => $actor->id,
                ]);
                $document->current_version_id = $version->id;
                $document->save();

                // Evidence changed underneath any working paper relying on it: invalidate sign-offs.
                foreach ($document->workingPapers()->get() as $wp) {
                    $this->papers->contentChanged($wp, $actor, "New version of linked evidence: {$document->title}");
                }

                $this->logger->log($isNew ? 'document.uploaded' : 'document.version_added',
                    ($isNew ? 'Uploaded ' : 'New version v'.$next.' of ').$document->title, $document,
                    ['version' => $next, 'sha256' => $hash, 'size' => $file->getSize()], $actor);

                return $version;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }

        ScanDocumentVersion::dispatch($document->firm_id, $version->id)->afterCommit();

        return $version;
    }

    public function download(DocumentVersion $version, User $actor): StreamedResponse
    {
        if (! $version->isDownloadable()) {
            abort(423, 'This file is not available until the malware scan has passed.');
        }

        $this->logger->log('document.downloaded', "Downloaded {$version->document->title} v{$version->version_no}", $version->document, ['version' => $version->version_no], $actor);

        $name = Str::ascii($version->original_name) ?: 'document';

        return Storage::disk($version->disk)->download($version->path, $name, [
            'Content-Type' => $version->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Archive (never delete): blocked while linked to a reviewed working paper. */
    public function archive(Document $document, User $actor): void
    {
        EngagementGuard::assertEditable($document->engagement);
        if ($document->workingPapers()->where('working_papers.status', WorkingPaperStatus::Reviewed->value)->exists()) {
            throw ValidationException::withMessages(['document' => 'This document is evidence on a reviewed working paper and cannot be archived.']);
        }

        DB::transaction(function () use ($document, $actor) {
            $document->archived_at = now();
            $document->save();
            $this->logger->log('document.archived', "Archived {$document->title}", $document, [], $actor);
        });
    }
}
