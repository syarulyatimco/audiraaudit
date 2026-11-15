<?php

namespace Tests\Feature\Ledger;

use App\Models\AgeingReport;
use App\Models\SamplingPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TrialBalance\TrialBalanceTestCase;

class AgeingTest extends TrialBalanceTestCase
{
    /** Aged receivables totalling 80,000 (the TB's trade debtors), with one credit balance. */
    private function rows(string $bigTotal = '45,000.00'): array
    {
        return [
            ['Aged Debtors Analysis as at 31/12/2025'], [],
            ['Debtor Code', 'Debtor Name', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '> 90 Days', 'Total'],
            ['300-A001', 'Alpha Trading Sdn Bhd', '20,000.00', '10,000.00', '5,000.00', '', '10,000.00', $bigTotal],
            ['300-B002', 'Beta Hardware', '15,000.00', '5,000.00', '', '', '', '20,000.00'],
            ['300-C003', 'Cahaya Logistics', '', '', '', '6,000.00', '10,000.00', '16,000.00'],
            ['300-D004', 'Delta Retail', '(1,000.00)', '', '', '', '', '(1,000.00)'],
            ['', 'Grand Total', '34,000.00', '15,000.00', '5,000.00', '6,000.00', '20,000.00', '80,000.00'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    public function test_receivables_ageing_reconciles_analyses_and_feeds_sampling(): void
    {
        $up = fn ($rows, $user = null) => $this->actingAs($user ?? $this->ctx['senior'])->post($this->base().'/ageing', ['type' => 'receivables', 'period' => 'current', 'as_at' => '31/12/2025', 'file' => $this->csv($rows, 'ageing.csv')]);
        $up($this->rows(), $this->ctx['staff'])->assertSessionHasErrors('file');
        $up($this->rows())->assertSessionHasNoErrors();
        $r = AgeingReport::sole();
        $this->assertSame([3, 'TR', '2025-12-31'], [$r->header_row, $r->fsli->code, $r->as_at->toDateString()]);
        $this->assertSame(['code' => 0, 'name' => 1, 'total' => 7], collect($r->column_map)->sortBy(fn ($v) => $v)->all());
        $this->assertSame([0, 1, 31, 61, 91], array_column($r->buckets, 'min_days'), 'bucket ages read from the headings');
        $this->actingAs($this->ctx['staff'])->get($this->base()."/ageing/{$r->uuid}")->assertOk()->assertSee('check the columns');

        $buckets = collect($r->buckets)->mapWithKeys(fn ($b) => [$b['col'] => ['col' => $b['col'], 'use' => '1', 'label' => $b['label'], 'min_days' => $b['min_days']]])->all();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ageing/{$r->uuid}", ['header_row' => 3, 'code' => 0, 'name' => 1, 'total' => 7, 'buckets' => $buckets])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame(['active', 4, 8_000_000], [$r->status, $r->line_count, (int) $r->total_sen], 'grand total row skipped');

        $a = app(\App\Services\Ledger\AgeingService::class)->analysis($r);
        $this->assertSame([3_400_000, 1_500_000, 500_000, 600_000, 2_000_000], array_column($a['buckets'], 'amount'));
        $this->assertSame(['tb' => 8_000_000, 'difference' => 0], ['tb' => $a['recon']['tb'], 'difference' => $a['recon']['difference']]);
        $this->assertSame(2_000_000, $a['over']['90']);
        $this->assertSame(['300-D004'], $a['wrong_side']->pluck('party_code')->all());
        $this->assertSame('300-A001', $a['top']->first()->party_code);
        $this->actingAs($this->ctx['staff'])->get($this->base()."/ageing/{$r->uuid}")->assertOk()->assertSee('Agreed ✓')->assertSee('Credit balances')->assertSee('Alpha Trading');

        try {
            DB::transaction(fn () => DB::table('ageing_lines')->where('ageing_report_id', $r->id)->delete());
            $this->fail('ageing lines must be immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Rows whose buckets do not add up to the total are rejected with the row number.
        $up($this->rows('46,000.00'))->assertSessionHasNoErrors();
        $bad = AgeingReport::where('status', 'pending')->sole();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ageing/{$bad->uuid}", ['header_row' => 3, 'code' => 0, 'name' => 1, 'total' => 7, 'buckets' => $buckets])
            ->assertSessionHasErrors(['map' => 'Row 4 (Alpha Trading Sdn Bhd): the ageing columns add up to 45,000.00, not the total 46,000.00.']);
        $this->actingAs($this->ctx['senior'])->post($this->base()."/ageing/{$bad->uuid}/discard")->assertRedirect();

        // Positive balances become a sampling population (e.g. confirmations).
        $this->actingAs($this->ctx['senior'])->post($this->base().'/sampling', ['title' => 'Debtor confirmations', 'objective' => 'Existence', 'test_type' => 'monetary', 'method' => 'random', 'approach' => 'non_statistical', 'engagement_fsli_id' => $this->fsliId('TR')])->assertSessionHasNoErrors();
        $plan = SamplingPlan::sole();
        $this->actingAs($this->ctx['senior'])->get($this->base()."/sampling/{$plan->uuid}")->assertOk()->assertSee('Load from ageing');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/sampling/{$plan->uuid}/population/ageing", ['report' => $r->uuid])->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame([3, 8_100_000], [$plan->population_count, (int) $plan->population_value_sen]);
        $this->assertSame(['300-A001', '300-B002', '300-C003'], $plan->populationItems()->orderBy('seq')->pluck('item_ref')->all());
    }
}
