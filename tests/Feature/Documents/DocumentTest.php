<?php

namespace Tests\Feature\Documents;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function setupEngagement(): array
    {
        [$firm, $partner] = $this->makeFirm();
        $senior = $this->staff($firm, FirmRole::Senior);
        $engagement = $this->engagement($this->client($partner), $partner, [[$senior, EngagementRole::Senior]]);

        return [$firm, $partner, $senior, $engagement];
    }

    private function pdf(string $name = 'bank-confirmation.pdf', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".$extra);
    }

    public function test_upload_hashes_scans_and_allows_download(): void
    {
        [, , $senior, $engagement] = $this->setupEngagement();
        $file = $this->pdf();
        $expected = hash_file('sha256', $file->getRealPath());

        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", ['file' => $file, 'category' => 'evidence', 'title' => 'Maybank confirmation'])
            ->assertRedirect();

        $doc = Document::with('currentVersion')->sole();
        $v = $doc->currentVersion;
        $this->assertSame($expected, $v->sha256);
        $this->assertSame(ScanStatus::Clean, $v->scan_status);
        $this->assertStringNotContainsString('bank-confirmation', $v->path, 'Storage key must not derive from client filename');
        Storage::disk('documents')->assertExists($v->path);

        $this->actingAs($senior)->get("/engagements/{$engagement->uuid}/documents/{$doc->uuid}/versions/{$v->uuid}/download")
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_malicious_upload_is_blocked_from_download(): void
    {
        [, , $senior, $engagement] = $this->setupEngagement();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", [
            'file' => UploadedFile::fake()->createWithContent('test.txt', self::EICAR), 'category' => 'evidence',
        ]);
        $v = DocumentVersion::sole();
        $this->assertSame(ScanStatus::Infected, $v->scan_status);
        $this->actingAs($senior)->get("/engagements/{$engagement->uuid}/documents/{$v->document->uuid}/versions/{$v->uuid}/download")->assertStatus(423);
    }

    public function test_disallowed_and_disguised_files_are_rejected(): void
    {
        [, , $senior, $engagement] = $this->setupEngagement();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", [
            'file' => UploadedFile::fake()->createWithContent('payload.exe', "MZ\x90\x00binary"), 'category' => 'evidence',
        ])->assertSessionHasErrors('file');
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", [
            'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "<?php system(\$_GET['c']); ?>"), 'category' => 'evidence',
        ])->assertSessionHasErrors('file');
        $this->assertSame(0, Document::count());
    }

    public function test_versions_are_immutable_at_database_level(): void
    {
        [, , $senior, $engagement] = $this->setupEngagement();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", ['file' => $this->pdf(), 'category' => 'evidence']);
        $v = DocumentVersion::sole();

        $this->expectException(QueryException::class);
        DB::table('document_versions')->where('id', $v->id)->update(['sha256' => str_repeat('0', 64)]);
    }

    public function test_new_version_increments_and_invalidates_linked_working_paper_signoffs(): void
    {
        [, $partner, $senior, $engagement] = $this->setupEngagement();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", ['file' => $this->pdf(), 'category' => 'evidence', 'title' => 'Bank']);
        $doc = Document::sole();

        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers", ['reference' => 'F1', 'title' => 'Cash', 'section' => 'fieldwork']);
        $wp = $engagement->workingPapers()->sole();
        $this->actingAs($senior)->put("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}", ['title' => 'Cash', 'conclusion' => 'Cash is fairly stated.']);
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/documents", ['document_id' => $doc->id]);
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer'])->assertSessionHasNoErrors();
        $this->assertSame('prepared', $wp->fresh()->status->value);

        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents/{$doc->uuid}/versions", ['file' => $this->pdf('bank-v2.pdf', 'v2')])->assertSessionHasNoErrors();

        $this->assertSame(2, $doc->fresh()->currentVersion->version_no);
        $this->assertSame('draft', $wp->fresh()->status->value);
        $this->assertSame(1, $wp->signoffs()->whereNotNull('invalidated_at')->count());
    }

    public function test_access_rules(): void
    {
        [$firm, $partner, $senior, $engagement] = $this->setupEngagement();
        $eqr = $this->staff($firm, FirmRole::Partner);
        $engagement->members()->forceCreate(['firm_id' => $firm->id, 'user_id' => $eqr->id, 'role' => EngagementRole::EqrReviewer]);
        $outsider = $this->staff($firm);
        [, $foreignPartner] = $this->makeFirm();

        $this->actingAs($eqr)->post("/engagements/{$engagement->uuid}/documents", ['file' => $this->pdf(), 'category' => 'evidence'])->assertForbidden();
        $this->actingAs($outsider)->get("/engagements/{$engagement->uuid}/documents")->assertForbidden();
        $this->actingAs($foreignPartner)->get("/engagements/{$engagement->uuid}/documents")->assertNotFound();

        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", ['file' => $this->pdf(), 'category' => 'evidence']);
        $doc = Document::sole();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents/{$doc->uuid}/archive")->assertForbidden();
        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/documents/{$doc->uuid}/archive")->assertRedirect();
        $this->assertNotNull($doc->fresh()->archived_at);
    }

    public function test_archive_blocked_for_evidence_on_reviewed_paper(): void
    {
        [$firm, $partner, $senior, $engagement] = $this->setupEngagement();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", ['file' => $this->pdf(), 'category' => 'evidence']);
        $doc = Document::sole();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers", ['reference' => 'F1', 'title' => 'Cash', 'section' => 'fieldwork']);
        $wp = $engagement->workingPapers()->sole();
        $this->actingAs($senior)->put("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}", ['title' => 'Cash', 'conclusion' => 'OK']);
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/documents", ['document_id' => $doc->id]);
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer']);
        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/signoff", ['role' => 'reviewer'])->assertSessionHasNoErrors();

        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/documents/{$doc->uuid}/archive")->assertSessionHasErrors('document');
    }

    public function test_document_tables_are_rls_protected(): void
    {
        [, , $senior, $engagement] = $this->setupEngagement();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/documents", ['file' => $this->pdf(), 'category' => 'evidence']);
        [$otherFirm] = $this->makeFirm();

        $this->tenant()->set($otherFirm->id);
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('document_versions')->count());
    }
}
