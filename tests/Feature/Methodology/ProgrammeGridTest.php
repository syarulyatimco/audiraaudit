<?php

namespace Tests\Feature\Methodology;

use App\Models\ActivityLog;
use App\Models\MethodologyLibrary;
use App\Models\ProgrammeRow;
use App\Models\ProgrammeSheet;
use App\Services\TrialBalance\SpreadsheetReader;
use App\Support\Spreadsheets\XlsxWriter;
use Database\Seeders\MethodologySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Planning\PlanningTestCase;
use Tests\Support\BuildsSamples;

class ProgrammeGridTest extends PlanningTestCase
{
    use BuildsSamples;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MethodologySeeder::class);
        $this->importTb();
        $this->mapAll();
    }

    private function template(): MethodologyLibrary
    {
        return MethodologyLibrary::whereNull('firm_id')->sole();
    }

    private function applyTemplate(): void
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/programmes/library', ['library' => $this->template()->uuid])->assertSessionHasNoErrors();
    }

    private function sheet(string $ref): ProgrammeSheet
    {
        return ProgrammeSheet::where('engagement_id', $this->ctx['engagement']->id)->where('reference', $ref)->sole();
    }

    private function rowUrl(ProgrammeSheet $s, ProgrammeRow $r, string $suffix = ''): string
    {
        return $this->base()."/programmes/{$s->uuid}/rows/{$r->uuid}{$suffix}";
    }

    public function test_applying_the_template_routes_programmes_to_the_engagement_and_each_mapped_fsli(): void
    {
        $this->actingAs($this->ctx['staff'])->post($this->base().'/programmes/library', ['library' => $this->template()->uuid])->assertForbidden();
        $this->applyTemplate();

        $e = $this->ctx['engagement']->fresh();
        $this->assertSame($this->template()->id, $e->methodology_library_id);
        $refs = $e->programmeSheets()->get()->groupBy(fn ($s) => $s->stage->value)->map(fn ($g) => $g->pluck('reference')->sort()->values()->all());

        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'A5'], $refs['planning']);
        // Cycles attach to the first listed FSLI present: revenue cycle → REV, purchases → COS, payroll → ADMIN.
        $this->assertSame(['ADMIN-C', 'CASH-C', 'COS-C', 'INV-C', 'PPE-C', 'REV-C'], $refs['controls']);
        // One substantive programme per mapped FSLI; RE is covered by the equity programme on SC and
        // ADMIN (no code-specific programme) falls back to the operating-expenses classification programme.
        $this->assertSame(['ADMIN-S', 'BORR-C-S', 'CASH-S', 'COS-S', 'INV-S', 'PPE-S', 'REV-S', 'SC-S', 'TR-S'], $refs['substantive']);

        $tr = $this->sheet('TR-S');
        $this->assertSame($this->fsliId('TR'), $tr->engagement_fsli_id);
        $first = $tr->rows()->first();
        $this->assertSame('TR-S.01', $first->ref);
        $this->assertStringContainsString('aged receivables listing', $first->procedure);
        $this->assertSame('ISA 500', $first->isa_refs);
        $this->assertSame(['TR-S.01', 'TR-S.02'], $tr->rows()->limit(2)->pluck('ref')->all());

        // Index page lists the sheets by stage.
        $this->actingAs($this->ctx['staff'])->get($this->base().'/programmes')->assertOk()->assertSee('Trade receivables')->assertSee('Controls testing');
        // Refresh adds nothing when every mapped FSLI already has its programmes.
        $this->actingAs($this->ctx['senior'])->post($this->base().'/programmes/refresh')->assertSessionHas('status', fn ($m) => str_contains($m, 'already'));
    }

    public function test_grid_cells_sign_offs_and_hash_bound_review(): void
    {
        $this->applyTemplate();
        $s = $this->sheet('TR-S');
        $row = $s->rows()->first();
        ['staff' => $staff, 'senior' => $senior, 'manager' => $manager, 'partner' => $partner] = $this->ctx;

        $this->actingAs($staff)->getJson($this->base()."/programmes/{$s->uuid}")->assertOk();
        $this->actingAs($staff)->patchJson($this->rowUrl($s, $row), ['field' => 'done_by', 'value' => 'Me'])->assertStatus(422);
        $this->actingAs($staff)->postJson($this->rowUrl($s, $row, '/done'))->assertStatus(422)->assertJsonPath('errors.row.0', 'Record the conclusion for TR-S.01 before signing it off.');
        $this->actingAs($staff)->patchJson($this->rowUrl($s, $row), ['field' => 'conclusion', 'value' => 'Listing agrees to GL; no exceptions.'])
            ->assertOk()->assertJsonPath('row.conclusion', 'Listing agrees to GL; no exceptions.');
        $this->actingAs($staff)->postJson($this->rowUrl($s, $row, '/done'))->assertOk()->assertJsonPath('row.done_by', $staff->name);

        $row->refresh();
        $this->assertSame($row->contentHash(), $row->signed_hash);
        $this->actingAs($staff)->postJson($this->rowUrl($s, $row, '/review'))->assertForbidden();   // staff cannot review
        $this->actingAs($manager)->postJson($this->rowUrl($s, $row, '/review'))->assertOk()->assertJsonPath('row.reviewed_by', $manager->name);

        // Editing a signed row clears both sign-offs and records what was cleared.
        $this->actingAs($senior)->patchJson($this->rowUrl($s, $row), ['field' => 'wp_ref', 'value' => 'E2/3'])->assertOk()->assertJsonPath('row.done_by', null);
        $row->refresh();
        $this->assertNull($row->reviewed_by);
        $cleared = ActivityLog::where('event', 'programme.signoffs_cleared')->sole();
        $this->assertSame($staff->id, $cleared->properties['done_by']);
        $this->assertSame($manager->id, $cleared->properties['reviewed_by']);

        // The preparer cannot review their own step.
        $this->actingAs($manager)->postJson($this->rowUrl($s, $row, '/done'))->assertOk();
        $this->actingAs($manager)->postJson($this->rowUrl($s, $row, '/review'))->assertStatus(422);
        $this->actingAs($partner)->postJson($this->rowUrl($s, $row, '/review'))->assertOk();
        // Only the reviewer may withdraw the review; the preparer can no longer withdraw.
        $this->actingAs($manager)->postJson($this->rowUrl($s, $row, '/unsign'))->assertStatus(422);
        $this->actingAs($partner)->postJson($this->rowUrl($s, $row, '/unsign'))->assertOk()->assertJsonPath('row.reviewed_by', null);

        // Signed rows cannot be deleted — by the service, nor at the database level.
        $this->actingAs($senior)->deleteJson($this->rowUrl($s, $row))->assertStatus(422);
        try {
            DB::transaction(fn () => DB::table('programme_rows')->where('id', $row->id)->delete());
            $this->fail('signed rows must not be deletable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('signed programme rows cannot be deleted', $e->getMessage());
        }
        // A review by the preparer is rejected by the database constraint too.
        try {
            DB::transaction(fn () => DB::table('programme_rows')->where('id', $row->id)->update(['reviewed_by' => $manager->id, 'reviewed_at' => now()]));
            $this->fail('self-review must be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('pr_review', $e->getMessage());
        }
    }

    public function test_rows_and_custom_columns_can_be_added_moved_and_removed(): void
    {
        $this->applyTemplate();
        $s = $this->sheet('CASH-S');
        $count = $s->rows()->count();
        $first = $s->rows()->first();
        ['staff' => $staff, 'senior' => $senior] = $this->ctx;

        $this->actingAs($staff)->postJson($this->base()."/programmes/{$s->uuid}/rows")->assertForbidden();
        $new = $this->actingAs($senior)->postJson($this->base()."/programmes/{$s->uuid}/rows", ['after' => $first->uuid])->assertOk()->json('row');
        $this->assertSame('CASH-S.'.sprintf('%02d', $count + 1), $new['ref']);
        $this->assertSame($new['uuid'], $s->rows()->get()[1]->uuid, 'inserted below the first row');

        $this->actingAs($senior)->postJson($this->base()."/programmes/{$s->uuid}/rows/{$new['uuid']}/move", ['direction' => -1])->assertOk();
        $this->assertSame($new['uuid'], $s->rows()->first()->uuid);

        $custom = $this->actingAs($senior)->postJson($this->base()."/programmes/{$s->uuid}/columns", ['label' => 'Sample size'])->assertOk()->json('custom');
        $key = $custom[0]['key'];
        $this->actingAs($senior)->postJson($this->base()."/programmes/{$s->uuid}/columns", ['label' => 'conclusion'])->assertStatus(422);
        $this->actingAs($staff)->patchJson($this->base()."/programmes/{$s->uuid}/rows/{$new['uuid']}", ['field' => "x:{$key}", 'value' => '25'])->assertOk()->assertJsonPath("row.extra.{$key}", '25');
        $this->actingAs($senior)->putJson($this->base()."/programmes/{$s->uuid}/columns/{$key}", ['label' => 'Items tested'])->assertOk()->assertJsonPath('custom.0.label', 'Items tested');
        $this->actingAs($senior)->deleteJson($this->base()."/programmes/{$s->uuid}/columns/{$key}")->assertOk()->assertJsonPath('custom', []);
        $this->assertSame([], ProgrammeRow::where('uuid', $new['uuid'])->sole()->extra);

        $this->actingAs($senior)->deleteJson($this->base()."/programmes/{$s->uuid}/rows/{$new['uuid']}")->assertOk();
        $this->assertSame($count, $s->rows()->count());
    }

    public function test_excel_round_trip_updates_rows_adds_rows_and_columns_and_ignores_sign_off_cells(): void
    {
        $this->applyTemplate();
        $s = $this->sheet('INV-S');
        $rows = $s->rows()->get();
        $signed = $rows[1];
        $signed->update(['conclusion' => 'Test counts agreed.']);
        $this->actingAs($this->ctx['staff'])->postJson($this->rowUrl($s, $signed, '/done'))->assertOk();

        $res = $this->actingAs($this->ctx['staff'])->get($this->base()."/programmes/{$s->uuid}/export")->assertOk();
        $path = $res->baseResponse->getFile()->getPathname();
        $read = app(SpreadsheetReader::class)->read($path, 'xlsx', 500, 4000);
        $header = collect($read)->search(fn ($r) => ($r[0] ?? '') === 'Ref');
        $this->assertSame(['Ref', 'Procedure', 'Assertion', 'Risk', 'Done by', 'Date', 'WP ref', 'Conclusion', 'Reviewed by', 'Row ID (do not edit)'], $read[$header]);
        $this->assertSame($this->ctx['staff']->name, $read[$header + 2][4]);
        $this->assertSame($signed->uuid, $read[$header + 2][9]);

        // Edit in "Excel": change row 1, tamper with the sign-off cell of row 2 (ignored), add a row and a column.
        $out = array_slice($read, $header);
        $out[0][] = 'Tested by client';
        $out[1][7] = 'Agreed with no exceptions';
        $out[2][4] = 'Someone else';
        $out[] = ['', 'Inspect consignment stock agreements', 'R', '', '', '', '', '', '', '', 'No'];
        $file = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        app(XlsxWriter::class)->write($file, 'INV-S', array_shift($out), array_map(fn ($r) => array_pad($r, 11, ''), $out));

        $this->actingAs($this->ctx['staff'])->post($this->base()."/programmes/{$s->uuid}/import", ['file' => new UploadedFile($file, 'inv.xlsx', null, null, true)])->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/programmes/{$s->uuid}/import", ['file' => new UploadedFile($file, 'inv.xlsx', null, null, true)])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($m) => str_contains($m, '1 row(s) updated, 1 added') && str_contains($m, '1 new column'));

        $s->refresh();
        $this->assertSame('Agreed with no exceptions', $rows[0]->fresh()->conclusion);
        $this->assertSame($this->ctx['staff']->id, $signed->fresh()->done_by, 'sign-off cells in the file are ignored');
        $added = $s->rows()->get()->last();
        $this->assertSame('Inspect consignment stock agreements', $added->procedure);
        $this->assertSame('Tested by client', $s->customColumns()[0]['label']);
        $this->assertSame('No', $added->extra[$s->customColumns()[0]['key']]);

        $bad = UploadedFile::fake()->createWithContent('x.xlsx', 'not a zip');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/programmes/{$s->uuid}/import", ['file' => $bad])->assertSessionHasErrors('file');
    }

    public function test_copy_as_link_tokens_resolve_in_grids_and_working_papers(): void
    {
        $this->applyTemplate();
        $s = $this->sheet('CASH-S');
        $step = $s->rows()->first();
        $doc = $this->uploadDocument('bank-confirmation.txt', 'Maybank confirms RM 45,000.00');

        $res = $this->actingAs($this->ctx['staff'])->patchJson($this->rowUrl($s, $s->rows()->get()[1]), ['field' => 'wp_ref', 'value' => "See [[step:{$step->uuid}]] and [[doc:{$doc->uuid}]]"])->assertOk();
        $html = $res->json('row.wp_ref_html');
        $this->assertStringContainsString('class="link-chip"', $html);
        $this->assertStringContainsString($step->ref, $html);
        $this->assertStringContainsString('bank-confirmation', $html);
        $this->assertStringContainsString('#row-'.$step->uuid, $html);

        // A token pasted into a working paper shows as a live shortcut.
        $this->actingAs($this->ctx['senior'])->post($this->base().'/working-papers', ['reference' => 'E9', 'title' => 'Cash summary', 'section' => 'fieldwork'])->assertSessionHasNoErrors();
        $wp = $this->ctx['engagement']->workingPapers()->where('title', 'Cash summary')->sole();
        $this->actingAs($this->ctx['senior'])->put($this->base()."/working-papers/{$wp->uuid}", ['title' => 'Cash summary', 'work_performed' => "Bank work: [[step:{$step->uuid}]]\nOther firm: [[step:00000000-0000-4000-8000-000000000000]]"])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['staff'])->get($this->base()."/working-papers/{$wp->uuid}")->assertOk()
            ->assertSee('Shortcuts in this paper')->assertSee($step->ref)->assertSee('broken link');

        // The plain URL form redirects to the step (and is refused to another firm).
        $this->actingAs($this->ctx['staff'])->get("/links/step/{$step->uuid}")->assertRedirect(route('programmes.show', [$this->ctx['engagement'], $s]).'#row-'.$step->uuid);
        $this->actingAs($this->ctx['staff'])->get("/links/doc/{$doc->uuid}")->assertRedirect(route('documents.show', [$this->ctx['engagement'], $doc]));
        [, $outsider] = $this->makeFirm();
        $this->actingAs($outsider)->get("/links/step/{$step->uuid}")->assertNotFound();
    }

    public function test_library_cannot_be_switched_after_a_step_is_signed_and_completion_gate_tracks_steps(): void
    {
        $this->applyTemplate();
        $copy = $this->actingAs($this->ctx['partner'])->post("/methodology/{$this->template()->uuid}/copy", ['name' => 'Firm way'])->assertSessionHasNoErrors();
        $firmLib = MethodologyLibrary::whereNotNull('firm_id')->sole();

        $gates = app(\App\Services\Completion\CompletionService::class)->completionGates($this->ctx['engagement']->fresh());
        $this->assertFalse($gates['programme_grid']['ok']);

        $s = $this->sheet('A1');
        $row = $s->rows()->first();
        $row->update(['conclusion' => 'Acceptance approved.']);
        $this->actingAs($this->ctx['staff'])->postJson($this->rowUrl($s, $row, '/done'))->assertOk();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/programmes/library', ['library' => $firmLib->uuid])->assertSessionHasErrors('library');
        $this->actingAs($this->ctx['senior'])->delete($this->base()."/programmes/{$s->uuid}")->assertSessionHasErrors('sheet');

        // With every step done and reviewed the gate passes.
        ProgrammeRow::query()->update(['done_by' => $this->ctx['staff']->id, 'done_at' => now(), 'signed_hash' => str_repeat('a', 64), 'reviewed_by' => $this->ctx['manager']->id, 'reviewed_at' => now()]);
        $gates = app(\App\Services\Completion\CompletionService::class)->completionGates($this->ctx['engagement']->fresh());
        $this->assertTrue($gates['programme_grid']['ok']);
    }

    public function test_switching_library_before_signing_replaces_the_sheets(): void
    {
        $this->applyTemplate();
        $this->actingAs($this->ctx['partner'])->post("/methodology/{$this->template()->uuid}/copy", ['name' => 'Firm way'])->assertSessionHasNoErrors();
        $lib = MethodologyLibrary::whereNotNull('firm_id')->sole();
        $prog = $lib->programmes()->where('ref_prefix', 'S-TR')->sole();
        $this->actingAs($this->ctx['partner'])->put("/methodology/{$lib->uuid}/programmes/{$prog->uuid}", ['stage' => 'substantive', 'scope' => 'fsli', 'fsli_codes' => 'TR', 'ref_prefix' => 'S-TR', 'title' => 'Debtors'])->assertSessionHasNoErrors();

        $this->actingAs($this->ctx['senior'])->post($this->base().'/programmes/library', ['library' => $lib->uuid])->assertSessionHasNoErrors();
        $this->assertSame('Trade receivables — Debtors', $this->sheet('TR-S')->title);
        $this->assertSame($lib->id, $this->ctx['engagement']->fresh()->methodology_library_id);
    }
}
