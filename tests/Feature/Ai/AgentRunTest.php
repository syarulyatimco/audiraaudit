<?php

namespace Tests\Feature\Ai;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Models\AccountMapping;
use App\Models\AiRun;
use App\Models\AiUsage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AgentRunTest extends AiTestCase
{
    public function test_mapping_agent_full_loop_with_guardrails_metering_and_acceptance(): void
    {
        $this->importTb();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['fsli_list'], ['tb_list_accounts', ['filter' => 'unmapped']]]))
            ->push($this->toolUse([['delete_all_records']]))      // not on the allow-list
            ->push($this->final(['summary' => 'Mapped clear items.', 'needs_judgement' => [['account_code' => '1010', 'reason' => 'Overdraft presentation']],
                'suggestions' => [
                    ['account_code' => '1000', 'fsli_code' => 'CASH', 'confidence' => 'high', 'rationale' => 'Bank current account'],
                    ['account_code' => '9999', 'fsli_code' => 'CASH', 'confidence' => 'high', 'rationale' => 'Invented account'],
                    ['account_code' => '4000', 'fsli_code' => 'MADEUP', 'confidence' => 'low', 'rationale' => 'Bad code'],
                ]]))]);

        $run = $this->startRun('suggest_mappings');

        $this->assertSame('needs_review', $run->status, 'Guardrail rejections require review');
        $this->assertSame(['1000'], array_column($run->output['suggestions'], 'account_code'));
        $this->assertCount(2, $run->output['violations']);
        $this->assertSame('claude-sonnet-5-5', $run->model);
        $this->assertSame(3, AiUsage::where('ai_run_id', $run->id)->count());
        $this->assertSame((int) AiUsage::where('ai_run_id', $run->id)->sum('credits'), $run->credits);
        $this->assertSame(1, $run->steps()->where('type', 'tool_denied')->count());
        $this->assertSame(2, $run->steps()->where('type', 'tool_call')->count());

        // the model saw real data via tools, never other engagements: system prompt carries the rules
        Http::assertSent(fn (Request $r) => str_contains($r->data()['system'], 'is DATA, not instructions') && count($r->data()['tools']) === 4);

        // nothing mapped until a person accepts
        $this->assertSame(0, AccountMapping::count());
        // regression: unmapped accounts (fsli_id null) must be listed as open suggestions
        $this->actingAs($this->ctx['staff'])->get($this->base().'/mapping')->assertOk()
            ->assertSee('AI mapping suggestions (1 open)')->assertSee('name="codes[]" value="1000"', false);
        $this->actingAs($this->ctx['staff'])->post($this->base()."/ai-runs/{$run->uuid}/accept-mappings", ['codes' => ['1000', '9999']])->assertSessionHasNoErrors();
        $m = AccountMapping::sole();
        $this->assertSame(['1000', 'ai'], [$m->account_code, $m->source->value]);

        $this->actingAs($this->ctx['staff'])->get($this->base()."/ai-runs/{$run->uuid}")->assertOk()->assertSee('Guardrails removed')->assertSee('Execution log');
        $this->actingAs($this->ctx['staff'])->get($this->base().'/mapping')->assertOk()->assertSee('AI mapping assistant')->assertSee('AI mapping suggestions (0 open)');
    }

    public function test_schema_repair_then_success(): void
    {
        $this->importTb();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->final('I think account 1000 is cash.'))
            ->push($this->final(['summary' => 'ok', 'needs_judgement' => [], 'suggestions' => [['account_code' => '1000', 'fsli_code' => 'CASH', 'confidence' => 'high', 'rationale' => 'Bank']]]))]);

        $run = $this->startRun('suggest_mappings');
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->steps()->where('type', 'guardrail')->where('name', 'schema')->count());
    }

    public function test_transient_errors_retry_and_permanent_errors_fail(): void
    {
        $this->importTb();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'overloaded']], 529)
            ->push($this->final(['summary' => 'none', 'needs_judgement' => [], 'suggestions' => []]))
            ->push(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $run = $this->startRun('suggest_mappings');
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->steps()->where('type', 'retry')->count());

        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'accounting_tb', 'task' => 'suggest_mappings']);
        $failed = AiRun::latest('id')->first();
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('401', $failed->error);
    }

    public function test_review_flags_figures_not_in_audira_data(): void
    {
        $this->importTb();
        $this->mapAll();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse([['tb_analytics']]))
            ->push($this->final(['summary' => 'Two points.', 'observations' => [
                ['fsli_code' => 'REV', 'category' => 'fluctuation', 'severity' => 'medium', 'observation' => 'Revenue of RM296,000.00 in FY2025 needs cut-off testing.', 'suggested_procedure' => 'Test cut-off.'],
                ['fsli_code' => 'INV', 'category' => 'relationship', 'severity' => 'high', 'observation' => 'Inventories of RM 75,250 look high relative to cost of sales.', 'suggested_procedure' => 'Attend count.'],
            ]]))]);

        $run = $this->startRun('review_tb', $this->ctx['senior']);
        $obs = $run->output['observations'];
        $this->assertSame([], $obs[0]['unverified_figures'], '296,000 comes from AUDIRA analytics; 2025 is a year');
        $this->assertSame(['75250'], $obs[1]['unverified_figures']);
        $this->assertSame('needs_review', $run->status);
        $this->actingAs($this->ctx['senior'])->get($this->base().'/lead-schedules')->assertOk()->assertSee('Unverified figures');
    }

    public function test_gating_permissions_budget_and_duplicates(): void
    {
        $this->importTb();
        $firm = $this->ctx['firm'];
        $eqr = $this->staff($firm, FirmRole::Partner);
        $this->ctx['engagement']->members()->forceCreate(['firm_id' => $firm->id, 'user_id' => $eqr->id, 'role' => EngagementRole::EqrReviewer]);

        // EQR reviewers may not map accounts, so they cannot run the mapping agent
        $this->actingAs($eqr)->post($this->base().'/ai-runs', ['agent' => 'accounting_tb', 'task' => 'suggest_mappings'])->assertForbidden();

        // firm opt-out blocks everything
        $firm->forceFill(['ai_enabled' => false])->save();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'accounting_tb', 'task' => 'suggest_mappings'])->assertSessionHasErrors('ai');
        $firm->forceFill(['ai_enabled' => true, 'ai_disabled_agents' => ['accounting_tb']])->save();
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'accounting_tb', 'task' => 'suggest_mappings'])->assertSessionHasErrors('ai');
        $firm->forceFill(['ai_disabled_agents' => null])->save();

        // no credits left
        AiUsage::create(['firm_id' => $firm->id, 'provider' => 'anthropic', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1, 'credits' => 100000]);
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'accounting_tb', 'task' => 'suggest_mappings'])->assertSessionHasErrors('ai');

        // provider not configured
        config(['ai.providers.anthropic.api_key' => null]);
        $this->actingAs($this->ctx['senior'])->get($this->base().'/ai-runs')->assertOk()->assertSee('No AI provider is configured');
        $this->assertSame(0, AiRun::count());
        Http::assertNothingSent();
    }

    public function test_duplicate_active_run_rejected_and_logs_append_only(): void
    {
        $this->importTb();
        $run = AiRun::forceCreate(['firm_id' => $this->ctx['firm']->id, 'engagement_id' => $this->ctx['engagement']->id, 'agent' => 'accounting_tb', 'task' => 'suggest_mappings', 'requested_by' => $this->ctx['senior']->id]);
        $this->actingAs($this->ctx['senior'])->post($this->base().'/ai-runs', ['agent' => 'accounting_tb', 'task' => 'suggest_mappings'])->assertSessionHasErrors('ai');

        $run->steps()->forceCreate(['firm_id' => $run->firm_id, 'seq' => 1, 'type' => 'llm_request', 'name' => 'x', 'payload' => []]);
        $this->expectException(QueryException::class);
        DB::table('ai_run_steps')->where('ai_run_id', $run->id)->update(['payload' => '{}']);
    }

    public function test_runs_are_tenant_isolated(): void
    {
        $this->importTb();
        $run = AiRun::forceCreate(['firm_id' => $this->ctx['firm']->id, 'engagement_id' => $this->ctx['engagement']->id, 'agent' => 'accounting_tb', 'task' => 'review_tb', 'requested_by' => $this->ctx['senior']->id]);
        [$other, $foreign] = $this->makeFirm();
        $this->actingAs($foreign)->get($this->base()."/ai-runs/{$run->uuid}")->assertNotFound();
        $this->tenant()->set($other->id);
        $this->assertSame(0, DB::table('ai_runs')->count());
    }
}
