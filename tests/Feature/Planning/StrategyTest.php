<?php

namespace Tests\Feature\Planning;

use App\Models\WorkingPaper;

class StrategyTest extends PlanningTestCase
{
    public function test_strategy_is_saved_gated_and_attested(): void
    {
        $this->importTb();
        $this->mapAll();
        $url = $this->base().'/planning/strategy';
        $this->actingAs($this->ctx['senior'])->put($url, ['controls_approach' => 'substantive', 'sections' => ['scope' => 'x']])->assertForbidden();

        $data = ['controls_approach' => 'substantive', 'fieldwork_start_on' => '2025-08-01', 'fieldwork_end_on' => '2025-07-01', 'sections' => ['scope' => 'Statutory audit under the Companies Act 2016.']];
        $this->actingAs($this->ctx['manager'])->put($url, $data)->assertSessionHasErrors('fieldwork_end_on');
        $data['fieldwork_end_on'] = '2025-08-20';
        $this->actingAs($this->ctx['manager'])->put($url, array_merge($data, ['sections' => ['scope' => 'Statutory audit under the Companies Act 2016.', 'bogus' => 'ignored']]))->assertSessionHasNoErrors();
        $strategy = $this->ctx['engagement']->auditStrategy;
        $this->assertSame(['scope'], array_keys($strategy->sections));

        $wp = WorkingPaper::where('system_key', WorkingPaper::AUDIT_STRATEGY)->sole();
        $this->assertSame('A1', $wp->reference);
        $sign = fn () => $this->actingAs($this->ctx['manager'])->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer']);
        $response = $sign();
        $response->assertSessionHasErrors('signoff');
        $errors = session('errors')->get('signoff');
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'Overall responses')));
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'approved materiality')));

        $this->approvedMateriality();
        $this->actingAs($this->ctx['manager'])->put($url, array_merge($data, ['sections' => [
            'scope' => 'Statutory audit under the Companies Act 2016.',
            'overall_responses' => 'Experienced senior assigned; unpredictable procedures on cash.',
            'resources' => 'Partner, manager, senior and one associate; 120 hours.',
        ]]))->assertSessionHasNoErrors();
        $sign()->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['partner'])->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'reviewer'])->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $wp->fresh()->status->value);

        $this->actingAs($this->ctx['manager'])->get($url)->assertOk()->assertSee('Signed off. Saving changes invalidates the sign-offs.');
        $this->actingAs($this->ctx['manager'])->put($url, array_merge($data, ['controls_approach' => 'combined', 'sections' => $this->ctx['engagement']->auditStrategy->fresh()->sections]))->assertSessionHasNoErrors();
        $this->assertSame('draft', $wp->fresh()->status->value);
    }
}
