<?php

namespace Tests\Feature\ClientFs;

use App\Models\ActivityLog;
use App\Models\ClientFinancialStatement;
use App\Models\ClientFsVersion;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Planning\PlanningTestCase;
use Tests\Support\BuildsClientFs;

/** ADR-051: management or the audit team may edit the working draft; only management submits. */
class SharedFsEditingTest extends PlanningTestCase
{
    use BuildsClientFs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    private function mfs(): string
    {
        return $this->base().'/management-fs';
    }

    public function test_the_audit_team_can_set_up_and_edit_the_draft_and_management_sees_it(): void
    {
        // Set up from the firm side when management has not started.
        $this->actingAs($this->ctx['manager'])->get($this->mfs())->assertOk()->assertSee('Set up draft');
        $this->actingAs($this->ctx['staff'])->get($this->mfs())->assertOk()->assertDontSee('Set up draft');
        $this->actingAs($this->ctx['staff'])->post($this->mfs().'/start', ['template' => 'company'])->assertSessionHasErrors('fs');
        $this->actingAs($this->ctx['senior'])->post($this->mfs().'/start', ['template' => 'company'])->assertSessionHasNoErrors()->assertRedirect();
        $fs = ClientFinancialStatement::sole();
        $this->assertSame('draft', $fs->status);

        // Auditor adds a new row with an amount at the end of the SOFP.
        $rows = $fs->statements['sofp']['rows'];
        $rows[] = ['id' => null, 'kind' => 'line', 'side' => 'asset', 'caption' => 'Right-of-use assets', 'note' => '7', 'cy' => '12,500.00', 'py' => ''];
        $res = $this->actingAs($this->ctx['manager'])->putJson($this->mfs().'/statements/sofp', ['rows' => $rows])->assertOk();
        $last = collect($res->json('rows'))->last();
        $this->assertSame('Right-of-use assets', $last['caption']);
        $this->assertSame(1_250_000, $last['cy']);
        $this->actingAs($this->ctx['staff'])->putJson($this->mfs().'/statements/sofp', ['rows' => []])->assertStatus(422);
        $this->actingAs($this->ctx['manager'])->putJson($this->mfs().'/statements/xyz', ['rows' => []])->assertNotFound();
        $this->actingAs($this->ctx['manager'])->putJson($this->mfs().'/notes', ['notes' => [['title' => 'Leases', 'body' => 'Drafted by the audit team for management to review.']]])->assertOk()->assertJsonCount(1, 'notes');

        // Management sees the auditor's row and can change it further.
        $client = $this->portalUser();
        $this->actingAs($client)->get($this->fsUrl())->assertOk()->assertSee('Right-of-use assets')->assertSee('The audit team edited');
        $rows = ClientFinancialStatement::sole()->statements['sofp']['rows'];
        $rows[count($rows) - 1]['cy'] = '13,000.00';
        $this->actingAs($client)->putJson($this->fsUrl().'/statements/sofp', ['rows' => $rows])->assertOk();

        // History records both sides with counts; a no-change save adds nothing.
        $edits = ActivityLog::where('event', 'client_fs.edited')->orderBy('id')->get();
        $this->assertCount(3, $edits);
        $this->assertSame('firm', $edits[0]->properties['side']);
        $this->assertSame(1, $edits[0]->properties['added']);
        $this->assertStringStartsWith('The audit team edited Statement of financial position', $edits[0]->description);
        $this->assertSame('client', $edits[2]->properties['side']);
        $this->assertSame(1, $edits[2]->properties['changed']);
        $this->actingAs($client)->putJson($this->fsUrl().'/statements/sofp', ['rows' => ClientFinancialStatement::sole()->statements['sofp']['rows']])->assertOk();
        $this->assertSame(3, ActivityLog::where('event', 'client_fs.edited')->count());
        $this->actingAs($this->ctx['manager'])->get($this->mfs().'?tab=history')->assertOk()->assertSee('Management edited')->assertSee('Change history');
    }

    public function test_only_management_submits_and_accepted_statements_are_frozen_for_everyone(): void
    {
        $this->preparedClientFs();
        $fs = ClientFinancialStatement::sole();

        // There is no firm submit route, and the service refuses a firm user.
        $this->actingAs($this->ctx['manager'])->post($this->mfs().'/submit')->assertNotFound();
        try {
            app(\App\Services\ClientFs\ClientFsService::class)->submit($fs, $this->ctx['manager'], true, 'A', now()->toDateString());
            $this->fail('only management submits');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Only management', $e->getMessage());
        }

        $this->actingAs($this->portalUser())->post($this->fsUrl().'/submit', ['directors_approved' => '1', 'approved_by_names' => 'Tan Sri A', 'approved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $v = ClientFsVersion::sole();
        $this->actingAs($this->ctx['manager'])->post($this->mfs()."/versions/{$v->uuid}/review", ['decision' => 'accept', 'comment' => 'P&L not tied in this test.'])->assertSessionHasNoErrors();

        $rows = $fs->fresh()->statements['sofp']['rows'];
        $this->actingAs($this->ctx['manager'])->putJson($this->mfs().'/statements/sofp', ['rows' => $rows])->assertStatus(422)->assertJsonPath('errors.fs.0', fn ($m) => str_contains($m, 'accepted'));
        $this->actingAs($this->portalUser())->putJson($this->fsUrl().'/statements/sofp', ['rows' => $rows])->assertStatus(422);

        // After re-opening, the audit team can edit again; the accepted version stays frozen.
        $this->actingAs($this->ctx['manager'])->post($this->mfs().'/reopen', ['reason' => 'Adjusting event'])->assertSessionHasNoErrors();
        $rows[] = ['kind' => 'line', 'side' => 'el', 'caption' => 'Provision for claims', 'cy' => '2,000.00'];
        $this->actingAs($this->ctx['manager'])->putJson($this->mfs().'/statements/sofp', ['rows' => $rows])->assertOk();
        $this->assertFalse(collect($v->fresh()->content['statements']['sofp']['rows'])->contains('caption', 'Provision for claims'));
    }

    public function test_the_audit_team_can_round_trip_the_draft_through_excel(): void
    {
        $this->preparedClientFs();
        $res = $this->actingAs($this->ctx['manager'])->get($this->mfs().'/export')->assertOk();
        $path = $res->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'fs').'.xlsx';
        copy($path, $copy);
        $this->actingAs($this->ctx['manager'])->post($this->mfs().'/import', ['file' => new UploadedFile($copy, 'fs.xlsx', null, null, true)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(ActivityLog::where('event', 'client_fs.imported')->where('description', 'like', 'The audit team%')->exists());
        $this->actingAs($this->ctx['staff'])->post($this->mfs().'/import', ['file' => new UploadedFile($copy, 'fs.xlsx', null, null, true)])->assertSessionHasErrors('fs');
    }
}
