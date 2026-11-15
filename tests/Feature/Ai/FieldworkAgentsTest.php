<?php

namespace Tests\Feature\Ai;

use App\Models\Procedure;
use App\Models\Risk;
use App\Models\SamplingPlan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class FieldworkAgentsTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks/presumed')->assertSessionHasNoErrors();
    }

    public function test_programme_agent_traces_procedures_to_real_risks(): void
    {
        $proc = fn (array $o) => array_merge(['risk_references' => ['R01'], 'fsli_code' => 'REV', 'title' => 'Revenue cut-off', 'steps' => '1. Test invoices either side of year end.',
            'nature' => 'test_of_details', 'timing' => 'final', 'assertions' => ['cut_off'], 'citations' => []], $o);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['risks_list'], ['procedures_list']]))
            ->push($this->final(['summary' => 'Three.', 'procedures' => [
                $proc([]),
                $proc(['title' => 'Unanchored procedure', 'risk_references' => ['R99']]),
                $proc(['title' => 'Journal testing', 'risk_references' => ['R02', 'R77'], 'fsli_code' => 'NOPE', 'steps' => 'Select 25 items from the journal listing.']),
            ]]))]);

        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'audit_programme', 'task' => 'draft_procedures'])->assertSessionHasNoErrors();
        $run = $this->ctx['engagement']->aiRuns()->first();
        $this->assertSame('needs_review', $run->status);
        $this->assertSame(['Revenue cut-off', 'Journal testing'], array_column($run->output['procedures'], 'title'));
        $this->assertSame(['R02'], $run->output['procedures'][1]['risk_references']);
        $this->assertNull($run->output['procedures'][1]['fsli_code']);
        $this->assertStringContainsString('states a sample size', implode(' ', $run->output['violations']));

        $this->actingAs($this->ctx['senior'])->get($this->base().'/programme')->assertOk()->assertSee('AI-drafted procedures (2)');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ai-runs/{$run->uuid}/accept-procedures", ['selected' => ['0', '1']])->assertSessionHasNoErrors();
        $this->assertSame(['ai', 'ai'], Procedure::pluck('source')->all());
        $this->assertSame([Risk::where('reference', 'R02')->value('id')], Procedure::where('title', 'Journal testing')->sole()->risks->pluck('id')->all());
        $this->actingAs($this->ctx['senior'])->get($this->base().'/programme/create')->assertOk();
    }

    public function test_sampling_agent_recommends_parameters_and_never_the_size(): void
    {
        $m = $this->ctx['engagement'];
        // Approved materiality via the real flow.
        $this->actingAs($this->ctx['senior'])->put($this->base().'/planning/materiality', ['benchmark' => 'profit_before_tax', 'benchmark_basis' => 'current_year', 'om_percentage' => '5', 'pm_percentage' => '75', 'ct_percentage' => '5', 'rationale' => 'PBT'])->assertSessionHasNoErrors();
        $mat = $m->materialityAssessments()->first();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/materiality/{$mat->uuid}/submit")->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['partner'])->post('/approvals/'.$mat->approvals()->pending()->sole()->uuid.'/decide', ['decision' => 'approve'])->assertSessionHasNoErrors();

        $this->actingAs($this->ctx['senior'])->post($this->base().'/sampling', ['title' => 'Debtors', 'objective' => 'Existence', 'test_type' => 'monetary', 'method' => 'mus', 'engagement_fsli_id' => $this->fsliId('TR')])->assertSessionHasNoErrors();
        $plan = SamplingPlan::sole();
        $rows = "Reference,Amount\nBIG,20000.00\n".implode("\n", array_map(fn ($i) => "I{$i},600.00", range(1, 100)));
        $this->actingAs($this->ctx['senior'])->post($this->base()."/sampling/{$plan->uuid}/population", ['file' => UploadedFile::fake()->createWithContent('p.csv', $rows)])->assertSessionHasNoErrors();

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['population_summary'], ['materiality_current']]))
            ->push($this->final(['confidence_level' => 95, 'tolerable_misstatement_sen' => 500_000, 'expected_misstatement_sen' => 0, 'key_item_threshold_sen' => 1_000_000,
                'tolerable_deviation_rate' => null, 'expected_deviation_rate' => null, 'rationale' => 'Significant risk of overstatement; one large balance tested in full.', 'method_comment' => null, 'citations' => []]))]);

        // The subject must belong to this engagement.
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'sampling', 'task' => 'recommend_parameters', 'subject' => '00000000-0000-0000-0000-000000000000'])->assertNotFound();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'sampling', 'task' => 'recommend_parameters', 'subject' => $plan->uuid])->assertSessionHasNoErrors();
        $run = $m->aiRuns()->first();
        $this->assertSame('completed', $run->status);

        $this->actingAs($this->ctx['senior'])->get($this->base()."/sampling/{$plan->uuid}")->assertOk()->assertSee('AI parameter recommendation');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ai-runs/{$run->uuid}/accept-sampling")->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame(['ai', 500_000, 1_000_000, 36], [$plan->source, $plan->tolerable_misstatement_sen, $plan->key_item_threshold_sen, $plan->sample_size]);
        $this->assertSame('draft', $plan->status->value, 'still needs approval');
        $this->actingAs($this->ctx['senior'])->get($this->base().'/sampling')->assertOk()->assertSee('S01');
    }

    public function test_programme_agent_suggests_rows_for_one_sheet(): void
    {
        $this->seed(\Database\Seeders\MethodologySeeder::class);
        $lib = \App\Models\MethodologyLibrary::whereNull('firm_id')->sole();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/programmes/library', ['library' => $lib->uuid])->assertSessionHasNoErrors();
        $sheet = \App\Models\ProgrammeSheet::where('reference', 'REV-S')->sole();
        $existing = $sheet->rows()->first()->procedure;
        $row = fn (array $o) => array_merge(['procedure' => 'Inspect credit notes issued in the month after year end and agree them to the original invoices.', 'assertions' => 'O, CO',
            'risk_references' => ['R01'], 'isa_refs' => 'ISA 330', 'rationale' => 'Revenue fraud risk; post year-end reversals.'], $o);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['programme_sheet'], ['risks_list']]))
            ->push($this->final(['summary' => 'Four.', 'rows' => [
                $row([]),
                $row(['procedure' => $existing]),                                                            // repeats an existing step
                $row(['procedure' => 'Test journals posted to revenue after year end.', 'risk_references' => ['R99']]),   // invented risk
                $row(['procedure' => 'Select 40 invoices and vouch them to delivery orders.', 'assertions' => 'O, X', 'isa_refs' => 'ISA 500.6', 'risk_references' => ['R01']]),
            ]]))]);

        // The subject must be a sheet of this engagement; staff cannot start it.
        $this->actingAs($this->ctx['staff'])->post($this->base().'/ai-runs', ['agent' => 'audit_programme', 'task' => 'suggest_rows', 'subject' => $sheet->uuid])->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'audit_programme', 'task' => 'suggest_rows', 'subject' => '00000000-0000-4000-8000-000000000000'])->assertNotFound();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'audit_programme', 'task' => 'suggest_rows', 'subject' => $sheet->uuid])->assertSessionHasNoErrors();
        $run = $this->ctx['engagement']->aiRuns()->first();
        $this->assertSame('needs_review', $run->status);
        $this->assertCount(2, $run->output['rows']);
        $this->assertSame(['O', 'ISA 500'], [$run->output['rows'][1]['assertions'], $run->output['rows'][1]['isa_refs']]);
        $v = implode(' ', $run->output['violations']);
        foreach (['repeats an existing step', 'R99', 'states a sample size', 'unknown assertion', 'paragraph numbers'] as $needle) {
            $this->assertStringContainsString($needle, $v);
        }
        $this->assertStringContainsString('REV-S', json_encode($run->steps()->where('type', 'tool_result')->where('name', 'programme_sheet')->first()->payload));

        $this->actingAs($this->ctx['senior'])->get($this->base()."/programmes/{$sheet->uuid}")->assertOk()->assertSee('AI-suggested steps (2)');
        $count = $sheet->rows()->count();
        $this->actingAs($this->ctx['staff'])->post($this->base()."/ai-runs/{$run->uuid}/accept-rows", ['selected' => ['0']])->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ai-runs/{$run->uuid}/accept-rows", ['selected' => ['0']])->assertSessionHasNoErrors();
        $new = $sheet->rows()->get()->last();
        $this->assertSame($count + 1, $sheet->rows()->count());
        $this->assertSame(['O, CO', 'R01', 'ISA 330', null], [$new->assertions, $new->risk, $new->isa_refs, $new->done_by]);
        $this->assertStringStartsWith('AI-suggested step (accepted by', $new->guidance);
    }
}
