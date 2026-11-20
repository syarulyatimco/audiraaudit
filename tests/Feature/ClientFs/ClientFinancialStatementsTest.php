<?php

namespace Tests\Feature\ClientFs;

use App\Models\ClientFinancialStatement;
use App\Models\ClientFsVersion;
use App\Services\TrialBalance\SpreadsheetReader;
use App\Support\Pdf\PdfRenderer;
use App\Support\Spreadsheets\XlsxWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Planning\PlanningTestCase;
use Tests\Support\BuildsClientFs;

class ClientFinancialStatementsTest extends PlanningTestCase
{
    use BuildsClientFs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    public function test_management_prepares_the_statements_in_the_portal(): void
    {
        $client = $this->portalUser();
        $this->actingAs($client)->get($this->fsUrl())->assertOk()->assertSee('Set up your financial statements');
        $this->actingAs($client)->post($this->fsUrl(), ['template' => 'nope'])->assertSessionHasErrors('template');
        $this->preparedClientFs();
        $fs = ClientFinancialStatement::sole();
        $this->assertEqualsCanonicalizing(['sofp', 'sopl', 'soce', 'cfs'], array_keys($fs->statements));
        $this->assertSame('Statement of cash flows', $fs->statements['cfs']['label']);
        $this->assertSame(12_000_000, $fs->statements['sofp']['rows'][0]['cy'], 'amounts parsed to sen');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $fs->statements['sofp']['rows'][0]['id']);
        $this->actingAs($client)->post($this->fsUrl(), ['template' => 'company'])->assertSessionHasErrors('template'); // once only

        // Validation of rows.
        $this->actingAs($client)->putJson($this->fsUrl().'/statements/sofp', ['rows' => [['kind' => 'line', 'caption' => 'Cash', 'cy' => '1']]])->assertStatus(422);
        $this->actingAs($client)->putJson($this->fsUrl().'/statements/sopl', ['rows' => [['kind' => 'line', 'caption' => 'Revenue', 'cy' => 'lots']]])->assertStatus(422)->assertJsonPath('errors.rows.0', 'Row 1 (Revenue): "lots" is not an amount.');
        $this->actingAs($client)->putJson($this->fsUrl().'/statements/sopl', ['rows' => [['kind' => 'line', 'caption' => 'Revenue', 'cy' => '296,000.00'], ['kind' => 'line', 'caption' => 'Cost of sales', 'cy' => '(120,000.00)']]])
            ->assertOk()->assertJsonPath('rows.1.cy', -12_000_000)->assertJsonPath('checks.sopl_result.0', 17_600_000);
        $this->actingAs($client)->putJson($this->fsUrl().'/notes', ['notes' => [['title' => 'Corporate information', 'body' => 'The company is a private limited company.'], ['title' => '', 'body' => '']]])
            ->assertOk()->assertJsonCount(1, 'notes');

        // Firm users cannot use the portal routes; staff (no edit_client_fs) are refused by the service.
        $this->actingAs($this->ctx['manager'])->putJson($this->fsUrl().'/statements/sofp', ['rows' => []])->assertForbidden();
        $this->assertSame('Property, plant and equipment', $fs->fresh()->statements['sofp']['rows'][0]['caption']);
        try {
            app(\App\Services\ClientFs\ClientFsService::class)->saveNotes($fs, [], $this->ctx['staff']);
            $this->fail('staff must not edit management\'s statements');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('cannot change', $e->getMessage());
        }

        // Another client's contact cannot see these statements.
        $other = \App\Models\User::forceCreate(['firm_id' => $this->ctx['firm']->id, 'client_id' => $this->client($this->ctx['partner'])->id, 'type' => 'client', 'name' => 'Other', 'email' => 'o@x.test', 'password' => 'x', 'status' => 'active']);
        $this->actingAs($other)->get($this->fsUrl())->assertNotFound();
        $this->actingAs($other)->putJson($this->fsUrl().'/notes', ['notes' => []])->assertNotFound();

        // The team sees the empty state until a version is submitted.
        $this->actingAs($this->ctx['staff'])->get($this->base().'/management-fs')->assertOk()->assertSee('Nothing submitted yet');
    }

    public function test_submission_review_tie_out_acceptance_and_reopening(): void
    {
        $this->preparedClientFs();
        $client = $this->portalUser();
        $url = $this->fsUrl();

        // A director-approved version must balance and carry names and a date.
        $this->actingAs($client)->post("{$url}/submit", ['directors_approved' => '1'])->assertSessionHasErrors('approved_by_names');
        $this->actingAs($client)->post("{$url}/submit", ['directors_approved' => '1', 'approved_by_names' => 'A', 'approved_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('approved_on');
        $this->actingAs($client)->post("{$url}/submit")->assertSessionHasNoErrors(); // draft for discussion
        $v1 = ClientFsVersion::sole();
        $this->assertSame([1, false, 'received'], [$v1->version, $v1->directors_approved, $v1->review_status]);

        // Submitted content is frozen at database level.
        try {
            DB::transaction(fn () => DB::table('client_fs_versions')->where('id', $v1->id)->update(['content' => '{}']));
            $this->fail('versions must be immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $review = $this->base()."/management-fs/versions/{$v1->uuid}/review";
        $this->actingAs($this->ctx['staff'])->post($review, ['decision' => 'request_changes', 'comment' => 'x'])->assertSessionHasErrors('review');
        $this->actingAs($this->ctx['manager'])->post($review, ['decision' => 'accept'])->assertSessionHasErrors('review'); // not director-approved
        $this->actingAs($this->ctx['manager'])->post($review, ['decision' => 'request_changes'])->assertSessionHasErrors('comment');
        $this->actingAs($this->ctx['manager'])->post($review, ['decision' => 'request_changes', 'comment' => 'Retained earnings should include the current-year profit.'])->assertSessionHasNoErrors();
        $this->actingAs($client)->get($url)->assertOk()->assertSee('Your auditors asked for changes to version 1');

        // Tie-out: captions matched to FSLIs; the 200,000 retained earnings differs from the audited 50,000.
        $fs = ClientFinancialStatement::sole();
        $tie = app(\App\Services\ClientFs\ClientFsService::class)->tieout($fs);
        $by = collect($tie['lines'])->keyBy('caption');
        $this->assertSame(['agreed', ['PPE'], true], [$by['Property, plant and equipment']['status'], $by['Property, plant and equipment']['codes'], $by['Property, plant and equipment']['guessed']]);
        $this->assertSame(['difference', 15_000_000], [$by['Retained earnings']['status'], $by['Retained earnings']['difference'][0]]);
        $this->assertSame(['agreed', ['BORR-C']], [$by['Borrowings (current)']['status'], $by['Borrowings (current)']['codes']]);
        $this->assertSame(['difference', -29_600_000], [$by['Revenue']['status'], $by['Revenue']['difference'][0]], 'a blank line is still compared with the audited revenue');
        // The team can map a line by hand; unknown codes are refused; clearing a map leaves the line untied.
        $this->actingAs($this->ctx['manager'])->put($this->base().'/management-fs/tieout', ['map' => [$by['Retained earnings']['id'] => 'RE, NOPE']])->assertSessionHasErrors('tieout');
        $this->actingAs($this->ctx['manager'])->put($this->base().'/management-fs/tieout', ['map' => [$by['Retained earnings']['id'] => 're', $by['Inventories']['id'] => '']])->assertSessionHasNoErrors();
        $by2 = collect(app(\App\Services\ClientFs\ClientFsService::class)->tieout($fs->fresh())['lines'])->keyBy('caption');
        $this->assertSame([['RE'], false], [$by2['Retained earnings']['codes'], $by2['Retained earnings']['guessed']]);
        $this->assertSame('unmapped', $by2['Inventories']['status']);

        // Version 2, approved by the directors; acceptance with a difference needs an explanation.
        $this->actingAs($client)->post("{$url}/submit", ['directors_approved' => '1', 'approved_by_names' => 'Tan Sri A', 'approved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $v2 = ClientFsVersion::where('version', 2)->sole();
        $this->assertSame('changes_requested', $v1->fresh()->review_status, 'v1 keeps its review');
        $this->actingAs($this->ctx['manager'])->get($this->base().'/management-fs')->assertOk()->assertSee('Review version 2')->assertSee('Tan Sri A');
        $this->actingAs($this->ctx['manager'])->post($this->base()."/management-fs/versions/{$v1->uuid}/review", ['decision' => 'accept', 'comment' => 'x'])->assertSessionHasErrors('review'); // not latest
        $r2 = $this->base()."/management-fs/versions/{$v2->uuid}/review";
        $this->actingAs($this->ctx['manager'])->post($r2, ['decision' => 'accept'])->assertSessionHasErrors('comment');
        $this->actingAs($this->ctx['manager'])->post($r2, ['decision' => 'accept', 'comment' => 'Retained earnings include current-year profit; P&L not yet tied.'])->assertSessionHasNoErrors();
        $this->assertSame(['accepted', 'accepted'], [$v2->fresh()->review_status, $fs->fresh()->status]);
        $this->assertSame($v2->id, $fs->fresh()->acceptedVersion()->id);

        // Accepted statements are locked for management until the team re-opens them.
        $this->actingAs($client)->putJson("{$url}/notes", ['notes' => []])->assertStatus(422);
        $this->actingAs($this->ctx['manager'])->post($this->base().'/management-fs/reopen', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->ctx['manager'])->post($this->base().'/management-fs/reopen', ['reason' => 'Adjusting event after the reporting date.'])->assertSessionHasNoErrors();
        $this->assertSame(['changes_requested', 'superseded'], [$fs->fresh()->status, $v2->fresh()->review_status]);
        $this->assertNull($fs->fresh()->acceptedVersion());
        $this->actingAs($client)->putJson("{$url}/notes", ['notes' => [['title' => 'Events after the reporting period', 'body' => 'Fire at warehouse.']]])->assertOk();
    }

    public function test_excel_round_trip_for_management(): void
    {
        $this->preparedClientFs();
        $client = $this->portalUser();
        $res = $this->actingAs($client)->get($this->fsUrl().'/export')->assertOk();
        $rows = app(SpreadsheetReader::class)->read($res->baseResponse->getFile()->getPathname(), 'xlsx', 500, 500);
        $h = collect($rows)->search(fn ($r) => ($r[0] ?? '') === 'Statement');
        $data = array_slice($rows, $h + 1);
        $this->assertSame(['sofp', 'line', 'asset', 'Property, plant and equipment', '', '120,000.00', ''], array_slice($data[0], 0, 7));

        $sofpId = $data[0][7];
        $data[0][6] = '110,000.00';                                                  // prior year added in Excel
        $data[] = ['sopl', 'line', '', 'Revenue', '12', '296,000.00', '250,000.00', ''];  // new P&L row
        $file = tempnam(sys_get_temp_dir(), 'f').'.xlsx';
        app(XlsxWriter::class)->write($file, 'FS', \App\Services\ClientFs\ClientFsService::XLSX_HEADERS, array_map(fn ($r) => array_pad($r, 8, ''), $data));
        $this->actingAs($client)->post($this->fsUrl().'/import', ['file' => new UploadedFile($file, 'fs.xlsx', null, null, true)])->assertSessionHasNoErrors();

        $fs = ClientFinancialStatement::sole();
        $this->assertSame([$sofpId, 11_000_000], [$fs->statements['sofp']['rows'][0]['id'], $fs->statements['sofp']['rows'][0]['py']], 'line ids kept for the tie-out');
        $last = collect($fs->statements['sopl']['rows'])->last();
        $this->assertSame(['Revenue', '12', 29_600_000, 25_000_000], [$last['caption'], $last['note'], $last['cy'], $last['py']]);
        $this->assertCount(16, $fs->statements['cfs']['rows'], 'statements absent from the file are untouched');
    }

    public function test_pdf_output_for_statements_and_report(): void
    {
        config(['audira.pdf.no_sandbox' => true]);
        if (! app(PdfRenderer::class)->available()) {
            $this->markTestSkipped('Chrome/Chromium is not installed.');
        }
        $this->preparedClientFs();
        $res = $this->actingAs($this->portalUser())->get($this->fsUrl().'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());

        $this->actingAs($this->portalUser())->post($this->fsUrl().'/submit')->assertSessionHasNoErrors();
        $v = ClientFsVersion::sole();
        $this->actingAs($this->ctx['staff'])->get($this->base()."/management-fs/pdf?version={$v->uuid}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->ctx['staff'])->get($this->base().'/audit-report/pdf')->assertNotFound(); // no report yet
    }
}
