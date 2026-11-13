<?php

namespace Tests\Feature\Library;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Enums\LibraryVersionStatus;
use App\Models\LibraryFramework;
use App\Models\LibraryParagraph;
use App\Models\LibraryStandard;
use App\Models\LibraryVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Uses synthetic test content only — never real standard text. */
class AuditLibraryTest extends TestCase
{
    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    private function publishedStandard(User $sa, string $label = 'Test edition 1'): LibraryStandard
    {
        $fw = LibraryFramework::firstOrCreate(['code' => 'test'], ['name' => 'Test framework', 'category' => 'auditing']);
        $st = LibraryStandard::firstOrCreate(['framework_id' => $fw->id, 'reference' => 'TS 100'], ['title' => 'Synthetic test standard']);
        $this->actingAs($sa)->post("/admin/library/standards/{$st->id}/versions", ['version_label' => $label, 'source_title' => 'Synthetic source', 'source_url' => 'https://example.org/source'])->assertRedirect();
        $v = LibraryVersion::where('version_label', $label)->sole();
        $csv = UploadedFile::fake()->createWithContent('p.csv', "paragraph_ref,heading,body\n1,Scope,Synthetic paragraph about sampling thresholds.\n2,,Second synthetic paragraph on materiality benchmarks.\n");
        $this->actingAs($sa)->post("/admin/library/versions/{$v->uuid}/import", ['csv' => $csv])->assertSessionHasNoErrors();
        $this->actingAs($sa)->post("/admin/library/versions/{$v->uuid}/publish")->assertSessionHasNoErrors();

        return $st;
    }

    public function test_superadmin_builds_and_publishes_versions_with_supersession(): void
    {
        Storage::fake('library');
        $sa = $this->superadmin();
        $this->actingAs($sa)->post('/admin/library/frameworks', ['code' => 'demo', 'name' => 'Demo framework', 'category' => 'auditing'])->assertSessionHasNoErrors();
        $fw = LibraryFramework::where('code', 'demo')->sole();
        $this->actingAs($sa)->post("/admin/library/frameworks/{$fw->id}/standards", ['reference' => 'DS 1', 'title' => 'Demo standard'])->assertSessionHasNoErrors();
        $st = $fw->standards()->sole();

        $this->actingAs($sa)->post("/admin/library/standards/{$st->id}/versions", [
            'version_label' => 'Edition A', 'source_title' => 'Demo source',
            'source_file' => UploadedFile::fake()->createWithContent('source.pdf', "%PDF-1.4\n%%EOF"),
        ])->assertRedirect();
        $v1 = LibraryVersion::sole();
        $this->assertNotNull($v1->source_sha256);

        $this->actingAs($sa)->post("/admin/library/versions/{$v1->uuid}/publish")->assertSessionHasErrors('version'); // no paragraphs
        $this->actingAs($sa)->post("/admin/library/versions/{$v1->uuid}/paragraphs", ['paragraph_ref' => '1', 'body' => 'Synthetic text.'])->assertSessionHasNoErrors();
        $this->actingAs($sa)->post("/admin/library/versions/{$v1->uuid}/paragraphs", ['paragraph_ref' => '1', 'body' => 'Dup'])->assertSessionHasErrors('paragraph_ref');
        $this->actingAs($sa)->post("/admin/library/versions/{$v1->uuid}/publish")->assertSessionHasNoErrors();
        $this->assertSame(LibraryVersionStatus::Published, $v1->fresh()->status);

        // published content is immutable — through the app and directly in SQL
        $p = $v1->paragraphs()->sole();
        $this->actingAs($sa)->put("/admin/library/versions/{$v1->uuid}/paragraphs/{$p->id}", ['paragraph_ref' => '1', 'body' => 'changed'])->assertSessionHasErrors('version');

        $this->actingAs($sa)->post("/admin/library/standards/{$st->id}/versions", ['version_label' => 'Edition B', 'source_title' => 'Demo source', 'source_url' => 'https://example.org/b']);
        $v2 = LibraryVersion::where('version_label', 'Edition B')->sole();
        $this->actingAs($sa)->post("/admin/library/versions/{$v2->uuid}/paragraphs", ['paragraph_ref' => '1', 'body' => 'Revised synthetic text.']);
        $this->actingAs($sa)->post("/admin/library/versions/{$v2->uuid}/publish");

        $this->assertSame(LibraryVersionStatus::Superseded, $v1->fresh()->status);
        $this->assertSame(LibraryVersionStatus::Published, $v2->fresh()->status);
        $this->actingAs($sa)->get('/admin/library')->assertOk()->assertSee('DS 1');
        $this->actingAs($sa)->get("/admin/library/versions/{$v1->uuid}")->assertOk()->assertSee('Immutable');
        $this->actingAs($sa)->get("/admin/library/versions/{$v1->uuid}/source")->assertOk();

        $this->expectException(QueryException::class);
        DB::table('library_paragraphs')->where('id', $p->id)->update(['body' => 'tampered']);
    }

    public function test_csv_import_is_all_or_nothing(): void
    {
        $sa = $this->superadmin();
        $fw = LibraryFramework::create(['code' => 'x', 'name' => 'X', 'category' => 'auditing']);
        $st = $fw->standards()->create(['reference' => 'X 1', 'title' => 'X']);
        $this->actingAs($sa)->post("/admin/library/standards/{$st->id}/versions", ['version_label' => 'E1', 'source_title' => 'S']);
        $v = LibraryVersion::sole();

        $bad = UploadedFile::fake()->createWithContent('p.csv', "paragraph_ref,body\n1,ok\n,missing ref\n");
        $this->actingAs($sa)->post("/admin/library/versions/{$v->uuid}/import", ['csv' => $bad])->assertSessionHasErrors('csv');
        $this->assertSame(0, $v->paragraphs()->count());
    }

    public function test_firm_users_see_published_only_and_cannot_curate(): void
    {
        $sa = $this->superadmin();
        $st = $this->publishedStandard($sa);
        $this->actingAs($sa)->post("/admin/library/standards/{$st->id}/versions", ['version_label' => 'Draft edition', 'source_title' => 'S']);
        $draft = LibraryVersion::where('version_label', 'Draft edition')->sole();
        $this->actingAs($sa)->post("/admin/library/versions/{$draft->uuid}/paragraphs", ['paragraph_ref' => '9', 'body' => 'Unpublished draft wording.']);

        [, $partner] = $this->makeFirm();
        $this->actingAs($partner)->get('/library')->assertOk()->assertSee('TS 100');
        $this->actingAs($partner)->get("/library/standards/{$st->id}")->assertOk()->assertSee('Synthetic paragraph about sampling')->assertDontSee('Unpublished draft wording');
        $this->actingAs($partner)->get("/library/standards/{$st->id}?version={$draft->uuid}")->assertNotFound();
        $this->actingAs($partner)->getJson('/library/search?q=materiality')->assertOk()->assertJsonCount(1)->assertJsonPath('0.citation', 'TS 100 ¶2 (Test edition 1)');
        $this->actingAs($partner)->getJson('/library/search?q=draft wording')->assertOk()->assertJsonCount(0);
        $this->actingAs($partner)->get('/admin/library')->assertForbidden();
    }

    public function test_working_paper_citations_use_published_paragraphs_and_survive_supersession(): void
    {
        $sa = $this->superadmin();
        $st = $this->publishedStandard($sa);
        [$firm, $partner] = $this->makeFirm();
        $senior = $this->staff($firm, FirmRole::Senior);
        $engagement = $this->engagement($this->client($partner), $partner, [[$senior, EngagementRole::Senior]]);
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers", ['reference' => 'D1', 'title' => 'Materiality', 'section' => 'materiality']);
        $wp = $engagement->workingPapers()->sole();
        $p = LibraryParagraph::where('paragraph_ref', '2')->sole();

        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}/citations", ['library_paragraph_id' => $p->id, 'note' => 'Benchmark selection'])->assertSessionHasNoErrors();
        $this->assertSame(1, $wp->citations()->count());

        $this->publishedStandard($sa, 'Test edition 2');
        $this->assertSame(LibraryVersionStatus::Superseded, $p->version->fresh()->status);
        $this->actingAs($senior)->get("/engagements/{$engagement->uuid}/working-papers/{$wp->uuid}")->assertOk()->assertSee('TS 100 ¶2 (Test edition 1)')->assertSee('Superseded');

        // superseded paragraphs cannot be newly cited
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers", ['reference' => 'D2', 'title' => 'Other', 'section' => 'materiality']);
        $wp2 = $engagement->workingPapers()->where('reference', 'D2')->sole();
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/working-papers/{$wp2->uuid}/citations", ['library_paragraph_id' => $p->id])->assertSessionHasErrors('library_paragraph_id');
    }
}
