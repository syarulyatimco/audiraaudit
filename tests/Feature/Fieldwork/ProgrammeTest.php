<?php

namespace Tests\Feature\Fieldwork;

use App\Models\Procedure;
use App\Models\Risk;
use App\Models\WorkingPaper;
use Tests\Feature\Planning\PlanningTestCase;

class ProgrammeTest extends PlanningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    private function procedureInput(array $o = []): array
    {
        return array_merge(['title' => 'Confirm trade receivables', 'steps' => '1. Send positive confirmations. 2. Perform alternative procedures for non-replies.',
            'nature' => 'test_of_details', 'timing' => 'final', 'assertions' => ['existence'], 'engagement_fsli_id' => $this->fsliId('TR')], $o);
    }

    public function test_procedures_trace_to_risks_and_programme_gates(): void
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks/presumed')->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks', $this->riskInput())->assertSessionHasNoErrors();
        [$revenue, $override, $tr] = Risk::orderBy('id')->get()->all();

        $url = $this->base().'/programme';
        $this->actingAs($this->ctx['staff'])->post($url, $this->procedureInput())->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post($url, $this->procedureInput(['assigned_to' => $this->staff($this->ctx['firm'])->id]))->assertSessionHasErrors('assigned_to');
        $this->actingAs($this->ctx['senior'])->post($url, $this->procedureInput(['risk_ids' => [$tr->id], 'assigned_to' => $this->ctx['staff']->id, 'budget_hours' => 6]))->assertSessionHasNoErrors();
        $p1 = Procedure::sole();
        $this->assertSame('P001', $p1->reference);
        $this->assertSame([$tr->id], $p1->risks->pluck('id')->all());

        $this->actingAs($this->ctx['senior'])->get($url)->assertOk()->assertSee('Risks without a responding procedure');
        $wp = WorkingPaper::where('system_key', WorkingPaper::AUDIT_PROGRAMME)->sole();
        $this->assertSame('A2', $wp->reference);
        $sign = fn () => $this->actingAs($this->ctx['senior'])->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer']);
        $sign()->assertSessionHasErrors('signoff');

        // A controls test alone does not satisfy a significant risk.
        $this->actingAs($this->ctx['senior'])->post($url, $this->procedureInput(['title' => 'Test journal approval controls', 'nature' => 'test_of_controls', 'engagement_fsli_id' => null, 'risk_ids' => [$override->id, $revenue->id], 'assigned_to' => $this->ctx['senior']->id]))->assertSessionHasNoErrors();
        $errors = $sign()->assertSessionHasErrors('signoff') ? session('errors')->get('signoff') : [];
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, "Significant risk {$override->reference} needs at least one substantive procedure")));

        $this->actingAs($this->ctx['senior'])->post($url, $this->procedureInput(['title' => 'Journal entry testing', 'steps' => 'Test journals posted after hours and to unusual accounts.', 'engagement_fsli_id' => null, 'risk_ids' => [$override->id], 'assigned_to' => $this->ctx['senior']->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post($url, $this->procedureInput(['title' => 'Revenue cut-off testing', 'steps' => 'Test sales either side of year end.', 'engagement_fsli_id' => $this->fsliId('REV'), 'risk_ids' => [$revenue->id], 'assigned_to' => $this->ctx['staff']->id]))->assertSessionHasNoErrors();
        $sign()->assertSessionHasNoErrors();

        // Execution: completion needs a conclusion; edits to the programme invalidate A2.
        $this->actingAs($this->ctx['staff'])->post($url."/{$p1->uuid}/progress", ['status' => 'completed'])->assertSessionHasErrors('conclusion');
        $this->actingAs($this->ctx['staff'])->post($url."/{$p1->uuid}/progress", ['status' => 'completed', 'conclusion' => 'All confirmations agreed.'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $p1->fresh()->status->value);
        $this->actingAs($this->ctx['senior'])->put($url."/{$p1->uuid}", $this->procedureInput(['risk_ids' => [$tr->id], 'assigned_to' => $this->ctx['staff']->id, 'budget_hours' => 8]))->assertSessionHasNoErrors();
        $this->assertSame('draft', $wp->fresh()->status->value);
        $this->actingAs($this->ctx['senior'])->get($url."/{$p1->uuid}")->assertOk()->assertSee('All confirmations agreed.');
    }
}
