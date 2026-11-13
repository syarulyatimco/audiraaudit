<?php

namespace Tests\Feature\Ai;

use App\Ai\Rag\LibraryRetriever;
use App\Models\AiRun;
use App\Models\LibraryFramework;
use App\Models\LibraryParagraph;
use App\Models\LibraryStandard;
use App\Models\LibraryVersion;
use App\Models\MaterialityAssessment;
use App\Models\Risk;
use App\Models\User;
use App\Models\WorkingPaper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class PlanningAgentsTest extends AiTestCase
{
    private LibraryParagraph $benchmarkPara;

    private LibraryParagraph $draftPara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();

        $sa = User::factory()->superadmin()->create();
        $fw = LibraryFramework::firstOrCreate(['code' => 'test'], ['name' => 'Test framework', 'category' => 'auditing']);
        $st = LibraryStandard::firstOrCreate(['framework_id' => $fw->id, 'reference' => 'TS 320'], ['title' => 'Synthetic standard']);
        $pub = LibraryVersion::forceCreate(['standard_id' => $st->id, 'version_label' => 'S1', 'status' => 'draft', 'source_title' => 'Synthetic', 'source_url' => 'https://example.org', 'created_by' => $sa->id]);
        $this->benchmarkPara = LibraryParagraph::forceCreate(['version_id' => $pub->id, 'paragraph_ref' => '10', 'heading' => 'Benchmarks', 'body' => 'Synthetic guidance about choosing a materiality benchmark such as profit before tax.']);
        $pub->forceFill(['status' => 'published', 'published_by' => $sa->id, 'published_at' => now()])->save();
        $draft = LibraryVersion::forceCreate(['standard_id' => $st->id, 'version_label' => 'S2 draft', 'status' => 'draft', 'source_title' => 'Synthetic', 'source_url' => 'https://example.org', 'created_by' => $sa->id]);
        $this->draftPara = LibraryParagraph::forceCreate(['version_id' => $draft->id, 'paragraph_ref' => '10', 'heading' => 'Benchmarks', 'body' => 'Unpublished draft text about materiality benchmark choices.']);
    }

    public function test_library_search_only_returns_published_paragraphs(): void
    {
        $hits = app(LibraryRetriever::class)->search('materiality benchmark');
        $this->assertSame([$this->benchmarkPara->id], $hits->pluck('id')->all());
        $this->assertFalse(app(LibraryRetriever::class)->vectorEnabled());
    }

    public function test_materiality_agent_amounts_citations_and_acceptance(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['fs_benchmarks'], ['entity_profile'], ['library_search', ['query' => 'materiality benchmark']]]))
            ->push($this->toolUse([['tb_list_accounts']]))   // not on this agent's allow-list
            ->push($this->final([
                'benchmark' => 'profit_before_tax', 'benchmark_year' => 'current_year', 'benchmark_amount_sen' => 16_000_000,
                'om_percentage' => 5, 'pm_percentage' => 70, 'ct_percentage' => 5,
                'rationale' => 'Profit before tax is the measure shareholders focus on; ISA 320 requires 5% of profit.',
                'pm_rationale' => 'First-year engagement, so a lower percentage.', 'ct_rationale' => 'Firm methodology.',
                'alternatives' => [['benchmark' => 'revenue', 'reason_rejected' => 'Profit is stable and positive.']],
                'specifics' => [['area' => 'Directors remuneration', 'amount_sen' => 50_000, 'rationale' => 'Sensitive'], ['area' => 'Too big', 'amount_sen' => 99_999_999, 'rationale' => 'x']],
                'citations' => [['paragraph_id' => $this->benchmarkPara->id, 'relevance' => 'Benchmark selection'], ['paragraph_id' => $this->draftPara->id, 'relevance' => 'Draft'], ['paragraph_id' => 999999, 'relevance' => 'Invented']],
                'summary' => 'PBT at 5%.',
            ]))]);

        $run = $this->startRun('recommend_materiality', null, 'materiality');

        $this->assertSame('needs_review', $run->status);
        $this->assertSame('claude-opus-5-5', $run->model);   // complex tier
        $out = $run->output;
        $this->assertSame(15_000_000, $out['benchmark_amount_sen'], 'corrected to the AUDIRA figure');
        $this->assertEquals(['om_sen' => 750_000, 'pm_sen' => 525_000, 'ct_sen' => 37_500], $out['computed']); // jsonb reorders keys
        $this->assertSame([$this->benchmarkPara->id], array_column($out['citations'], 'paragraph_id'));
        $this->assertCount(1, $out['specifics']);
        $v = implode(' | ', $out['violations']);
        $this->assertStringContainsString('corrected', $v);
        $this->assertStringContainsString('presents a percentage as required', $v);
        $this->assertStringContainsString('#999999', $v);
        $this->assertSame(1, $run->steps()->where('type', 'tool_denied')->count());
        Http::assertSent(fn (Request $r) => collect($r->data()['tools'])->pluck('name')->sort()->values()->all() === ['entity_profile', 'fs_benchmarks', 'library_search']);

        // Staff may not start it (needs engagement.prepare_materiality).
        $this->actingAs($this->ctx['staff'])->post($this->base().'/ai-runs', ['agent' => 'materiality', 'task' => 'recommend_materiality'])->assertForbidden();

        $this->actingAs($this->ctx['senior'])->get($this->base().'/planning/materiality')->assertOk()->assertSee('AI materiality recommendation')->assertSee('Use as draft');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ai-runs/{$run->uuid}/accept-materiality")->assertSessionHasNoErrors();
        $m = MaterialityAssessment::sole();
        $this->assertSame(['draft', 'ai', $run->id, 750_000, 525_000], [$m->status->value, $m->source, $m->ai_run_id, $m->om_sen, $m->pm_sen]);
        $this->assertStringContainsString('Alternatives considered', $m->rationale);
        $d1 = WorkingPaper::where('system_key', WorkingPaper::MATERIALITY)->sole();
        $this->assertSame([$this->benchmarkPara->id], $d1->citations()->pluck('library_paragraph_id')->all());
        $this->assertNull($m->approved_at, 'the agent never approves');
    }

    public function test_risk_agent_guardrails_and_acceptance(): void
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/planning/risks', [
            'title' => 'Existing risk', 'description' => 'Already recorded', 'level' => 'financial_statement', 'likelihood' => 'low', 'magnitude' => 'low',
        ])->assertSessionHasNoErrors();

        $risk = fn (array $o) => array_merge([
            'description' => 'Debtors grew while sales were flat.', 'basis' => 'Trade receivables of 80,000.00 per tb_analytics.',
            'level' => 'assertion', 'fsli_codes' => ['TR'], 'assertions' => ['valuation_allocation'], 'inherent_factors' => ['subjectivity'],
            'likelihood' => 'medium', 'magnitude' => 'high', 'suggest_significant' => true, 'fraud' => false,
            'planned_response' => 'Test recoverability through subsequent receipts.', 'citations' => [],
        ], $o);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['tb_analytics'], ['fsli_list'], ['risks_list'], ['library_search', ['query' => 'materiality benchmark']]]))
            ->push($this->final(['summary' => 'Two risks.', 'risks' => [
                $risk(['title' => 'Recoverability of trade receivables', 'citations' => [['paragraph_id' => $this->benchmarkPara->id, 'relevance' => 'test']]]),
                $risk(['title' => 'Inventory valuation', 'fsli_codes' => ['NOPE'], 'basis' => 'Stock of 3,100,000 seems high.']),
                $risk(['title' => 'Existing risk']),
                $risk(['title' => 'Going concern pressure', 'level' => 'financial_statement', 'fsli_codes' => ['TR'], 'assertions' => ['existence'], 'basis' => 'Entity understanding.']),
            ]]))]);

        $run = $this->startRun('suggest_risks', null, 'risk_assessment');
        $this->assertSame('needs_review', $run->status);
        $titles = array_column($run->output['risks'], 'title');
        $this->assertSame(['Recoverability of trade receivables', 'Going concern pressure'], $titles);
        $this->assertSame([], $run->output['risks'][1]['fsli_codes']);
        $this->assertSame([], $run->output['risks'][0]['unverified_figures'], '80,000 is in the tool data');
        $v = implode(' | ', $run->output['violations']);
        $this->assertStringContainsString('NOPE', $v);
        $this->assertStringContainsString('already in the risk register', $v);

        $this->actingAs($this->ctx['senior'])->get($this->base().'/planning/risks')->assertOk()->assertSee('AI-proposed risks (2)');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ai-runs/{$run->uuid}/accept-risks", ['selected' => ['0', '1'], 'significant' => ['0']])->assertSessionHasNoErrors(); // strings, as a browser sends them

        $added = Risk::where('source', 'ai')->orderBy('id')->get();
        $this->assertSame(['Recoverability of trade receivables', 'Going concern pressure'], $added->pluck('title')->all());
        $this->assertTrue($added[0]->is_significant);
        $this->assertNull($added[0]->significance_status, 'significance still needs partner approval');
        $this->assertSame([$this->fsliId('TR')], $added[0]->fslis->pluck('id')->all());
        $this->assertSame($run->id, $added[0]->ai_run_id);
        $this->assertSame([$this->benchmarkPara->id], WorkingPaper::where('system_key', WorkingPaper::RISK_ASSESSMENT)->sole()->citations()->pluck('library_paragraph_id')->all());

        // Accepting again does not duplicate.
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ai-runs/{$run->uuid}/accept-risks", ['selected' => [0, 1]])->assertSessionHasNoErrors();
        $this->assertSame(2, Risk::where('source', 'ai')->count());
        $this->assertSame(0, AiRun::where('status', 'running')->count());
    }
}
