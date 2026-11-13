<?php

namespace App\Services\Library;

use App\Enums\LibraryVersionStatus;
use App\Models\LibraryParagraph;
use App\Models\LibraryStandard;
use App\Models\LibraryVersion;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Version-controlled Audit Library. Content is entered only from authorised source material
 * (recorded on every version with title, URL/file and hash). Publishing freezes the version;
 * the previously published version becomes "superseded" but stays citable history.
 */
class LibraryService
{
    public function __construct(private ActivityLogger $logger) {}

    /** @param array<string, mixed> $data */
    public function createVersion(LibraryStandard $standard, array $data, ?UploadedFile $source, User $actor): LibraryVersion
    {
        return DB::transaction(function () use ($standard, $data, $source, $actor) {
            $version = $standard->versions()->create([...$data, 'created_by' => $actor->id]);
            if ($source) {
                $this->attachSource($version, $source);
            }
            $this->logger->log('library.version_created', "Draft {$version->version_label} created for {$standard->reference}", $version, [], $actor, null);

            return $version;
        });
    }

    public function attachSource(LibraryVersion $version, UploadedFile $file): void
    {
        $this->assertDraft($version);
        $path = 'sources/'.$version->uuid.'/'.Str::uuid().'.bin';
        Storage::disk('library')->putFileAs(dirname($path), $file, basename($path));
        $version->forceFill([
            'source_disk' => 'library', 'source_path' => $path,
            'source_filename' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'source_sha256' => hash_file('sha256', $file->getRealPath()),
        ])->save();
    }

    /** @param array{paragraph_ref: string, heading?: ?string, body: string} $data */
    public function addParagraph(LibraryVersion $version, array $data): LibraryParagraph
    {
        $this->assertDraft($version);
        if ($version->paragraphs()->where('paragraph_ref', $data['paragraph_ref'])->exists()) {
            throw ValidationException::withMessages(['paragraph_ref' => 'This paragraph reference already exists in the version.']);
        }

        return $version->paragraphs()->create([...$data, 'sort_order' => (int) $version->paragraphs()->max('sort_order') + 1]);
    }

    /** @param array{paragraph_ref: string, heading?: ?string, body: string} $data */
    public function updateParagraph(LibraryParagraph $paragraph, array $data): void
    {
        $this->assertDraft($paragraph->version);
        $paragraph->update($data);
    }

    public function deleteParagraph(LibraryParagraph $paragraph): void
    {
        $this->assertDraft($paragraph->version);
        $paragraph->delete();
    }

    /**
     * Import paragraphs from CSV with header: paragraph_ref,heading,body
     *
     * @return int rows imported
     */
    public function importCsv(LibraryVersion $version, UploadedFile $csv, User $actor): int
    {
        $this->assertDraft($version);
        $handle = fopen($csv->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \t")), fgetcsv($handle) ?: []);
        if (! in_array('paragraph_ref', $header, true) || ! in_array('body', $header, true)) {
            throw ValidationException::withMessages(['csv' => 'The CSV must have a header row with paragraph_ref, heading (optional) and body columns.']);
        }

        $rows = [];
        $line = 1;
        while (($cols = fgetcsv($handle)) !== false) {
            $line++;
            if ($cols === [null]) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($cols, 0, count($header)), count($header), null));
            $ref = trim((string) ($row['paragraph_ref'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));
            if ($ref === '' || $body === '' || mb_strlen($ref) > 40) {
                throw ValidationException::withMessages(['csv' => "Line {$line}: paragraph_ref (max 40 chars) and body are required."]);
            }
            if (isset($rows[$ref])) {
                throw ValidationException::withMessages(['csv' => "Line {$line}: duplicate paragraph_ref {$ref}."]);
            }
            $rows[$ref] = ['paragraph_ref' => $ref, 'heading' => trim((string) ($row['heading'] ?? '')) ?: null, 'body' => $body];
        }
        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages(['csv' => 'The CSV contains no paragraphs.']);
        }

        return DB::transaction(function () use ($version, $rows, $actor) {
            $existing = $version->paragraphs()->pluck('paragraph_ref')->all();
            if ($dupes = array_intersect(array_keys($rows), $existing)) {
                throw ValidationException::withMessages(['csv' => 'Paragraphs already exist: '.implode(', ', array_slice($dupes, 0, 5))]);
            }
            $order = (int) $version->paragraphs()->max('sort_order');
            foreach ($rows as $row) {
                $version->paragraphs()->create([...$row, 'sort_order' => ++$order]);
            }
            $this->logger->log('library.paragraphs_imported', count($rows)." paragraphs imported into {$version->standard->reference} {$version->version_label}", $version, [], $actor, null);

            return count($rows);
        });
    }

    public function publish(LibraryVersion $version, User $actor): void
    {
        $this->assertDraft($version);
        if (! $version->paragraphs()->exists()) {
            throw ValidationException::withMessages(['version' => 'Add at least one paragraph before publishing.']);
        }
        if (blank($version->source_url) && blank($version->source_path)) {
            throw ValidationException::withMessages(['version' => 'Record the authorised source (URL or file) before publishing.']);
        }

        DB::transaction(function () use ($version, $actor) {
            LibraryVersion::where('standard_id', $version->standard_id)->where('status', LibraryVersionStatus::Published->value)
                ->update(['status' => LibraryVersionStatus::Superseded->value, 'superseded_at' => now()]);
            $version->forceFill(['status' => LibraryVersionStatus::Published, 'published_by' => $actor->id, 'published_at' => now()])->save();
            $this->logger->log('library.version_published', "{$version->standard->reference} {$version->version_label} published", $version, [], $actor, null);
        });
        if (app(\App\Ai\Rag\LibraryRetriever::class)->vectorEnabled()) {
            \App\Jobs\EmbedLibraryVersion::dispatch($version->id)->afterCommit();
        }
    }

    private function assertDraft(LibraryVersion $version): void
    {
        if (! $version->isDraft()) {
            throw ValidationException::withMessages(['version' => 'Published and superseded versions are immutable. Create a new version instead.']);
        }
    }
}
