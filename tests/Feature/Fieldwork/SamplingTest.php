<?php

namespace Tests\Feature\Fieldwork;

use App\Enums\FirmRole;
use App\Models\SampleItem;
use App\Models\SamplingPlan;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Planning\PlanningTestCase;

class SamplingTest extends PlanningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    /** Receivables population reconciling to the TR balance of 80,000.00: one 20,000.00 item and 100 × 600.00. */
    private function population(int $smallItems = 100, string $small = '600.00', string $big = '20,000.00'): UploadedFile
    {
        $rows = "Reference,Customer,Amount,Date\nINV-0000,Big Customer Sdn Bhd,\"{$big}\",2025-06-30\n";
        for ($i = 1; $i <= $smallItems; $i++) {
            $rows .= sprintf("INV-%04d,Customer %d,%s,2025-06-%02d\n", $i, $i, $small, ($i % 28) + 1);
        }

        return UploadedFile::fake()->createWithContent('debtors.csv', $rows);
    }

    private function makePlan(array $o = []): SamplingPlan
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/sampling', array_merge([
            'title' => 'Debtors confirmation', 'objective' => 'Obtain evidence of existence of trade receivables.', 'test_type' => 'monetary', 'method' => 'mus',
            'engagement_fsli_id' => $this->fsliId('TR'),
        ], $o))->assertSessionHasNoErrors();

        return SamplingPlan::latest('id')->first();
    }

    private function url(SamplingPlan $p, string $suffix = ''): string
    {
        return $this->base()."/sampling/{$p->uuid}{$suffix}";
    }

    public function test_mus_end_to_end_with_approval_reproducible_selection_and_evaluation(): void
    {
        $plan = $this->makePlan();
        $this->assertSame(['S01', 'statistical'], [$plan->reference, $plan->approach->value]);
        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/population'), ['file' => $this->population()])->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame([101, 8_000_000], [$plan->population_count, $plan->population_value_sen]);
        $this->assertSame(64, strlen($plan->population_sha256));

        $params = ['tolerable_misstatement' => '5,000.00', 'expected_misstatement' => '0', 'key_item_threshold' => '10,000', 'confidence_level' => '95'];
        // Materiality must be approved, and TM may not exceed performance materiality (5,625.00).
        $this->actingAs($this->ctx['senior'])->put($this->url($plan, '/parameters'), $params)->assertSessionHasErrors('tolerable_misstatement');
        $this->approvedMateriality();
        $this->actingAs($this->ctx['senior'])->put($this->url($plan, '/parameters'), ['tolerable_misstatement' => '6,000'] + $params)->assertSessionHasErrors('tolerable_misstatement');
        $this->actingAs($this->ctx['senior'])->put($this->url($plan, '/parameters'), $params)->assertSessionHasNoErrors();
        $plan->refresh();
        // Reconciled to the TR adjusted balance; non-key value 60,000 → ceil(60,000 × 2.9957 / 5,000) = 36.
        $this->assertSame(0, $plan->reconciliationDifference());
        $this->assertSame(36, $plan->sample_size);
        $this->assertSame(166_666, $plan->size_calculation['interval_sen']);

        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/select'))->assertSessionHasErrors('plan'); // not approved
        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/submit'), ['comment' => 'MUS at 95%'])->assertSessionHasNoErrors();
        $approval = $plan->approvals()->pending()->sole();
        $this->decide($approval, $this->ctx['senior'])->assertForbidden();            // requester
        $this->decide($approval, $this->ctx['manager'])->assertSessionHasNoErrors();  // manager may approve sampling plans
        $this->assertSame('approved', $plan->fresh()->status->value);

        // Population and parameters are frozen once approved.
        try {
            DB::transaction(fn () => DB::table('population_items')->where('sampling_plan_id', $plan->id)->limit(1)->update(['amount_sen' => 1]));
            $this->fail('population must be frozen');
        } catch (QueryException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }

        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/select'))->assertSessionHasNoErrors();
        $plan->refresh();
        $items = $plan->sampleItems()->with('populationItem')->get();
        $this->assertNotNull($plan->selection_seed);
        $this->assertSame('php-mt19937 v1', $plan->selection_algorithm);
        $this->assertSame('INV-0000', $items->first()->populationItem->item_ref);
        $this->assertSame('key_item', $items->first()->selection_reason);
        $this->assertSame(37, $items->count()); // key item + 36 monetary units on 600.00 items (interval 1,666.66)
        $this->assertTrue(app(\App\Services\Sampling\SamplingService::class)->verifySelection($plan));
        try {
            DB::transaction(fn () => DB::table('sample_items')->where('id', $items[1]->id)->update(['population_item_id' => $items[2]->population_item_id]));
            $this->fail('selection must be immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Results: one 50% overstatement on a 600.00 item.
        $this->actingAs($this->ctx['staff'])->post($this->url($plan, "/items/{$items[1]->id}"), ['result' => 'exception'])->assertSessionHasErrors('audited_amount');
        $this->actingAs($this->ctx['staff'])->post($this->url($plan, "/items/{$items[1]->id}"), ['result' => 'exception', 'audited_amount' => '300.00', 'result_note' => 'Credit note not recorded'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/evaluate'), ['conclusion' => 'x'])->assertSessionHasErrors('plan'); // untested items remain
        foreach ($items->slice(2)->prepend($items[0]) as $it) {
            $this->actingAs($this->ctx['staff'])->post($this->url($plan, "/items/{$it->id}"), ['result' => 'no_exception'])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/evaluate'), ['conclusion' => 'Upper limit exceeds tolerable: extend testing and propose an adjustment.'])->assertSessionHasNoErrors();
        $e = $plan->fresh()->evaluation;
        $this->assertSame(1, $e['errors']);
        $this->assertSame(83_333, $e['projected_overstatement_sen']);          // 0.5 × 166,666
        $this->assertTrue($e['further_procedures_indicated'], 'misstatement exceeds the zero expected in planning');
        // Basic precision (2.995732 × 166,666 = 499,287) nearly uses up TM 500,000, so one error breaches it.
        $this->assertSame(499_287, $e['basic_precision_sen']); // 2.995732 × 166,666
        $this->assertTrue($e['exceeds_tolerable']);
        $this->assertGreaterThan(500_000, $e['upper_misstatement_limit_sen']);
        $this->actingAs($this->ctx['senior'])->get($this->url($plan))->assertOk()->assertSee('Same items selected')->assertSee('Upper Misstatement Limit');
    }

    public function test_population_must_reconcile_or_be_explained(): void
    {
        $this->approvedMateriality();
        $plan = $this->makePlan();
        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/population'), ['file' => $this->population(90)])->assertSessionHasNoErrors(); // 74,000.00
        $params = ['tolerable_misstatement' => '5,000', 'confidence_level' => '95'];
        $this->actingAs($this->ctx['senior'])->put($this->url($plan, '/parameters'), $params)->assertSessionHasErrors('reconciliation_note');
        $this->actingAs($this->ctx['senior'])->put($this->url($plan, '/parameters'), $params + ['reconciliation_note' => 'Credit balances of 6,000.00 tested separately.'])->assertSessionHasNoErrors();
        $this->assertSame(-600_000, $plan->fresh()->reconciliationDifference());

        $bad = UploadedFile::fake()->createWithContent('bad.csv', "Reference,Amount\nA1,100\nA1,200\nA3,abc\n");
        $this->actingAs($this->ctx['senior'])->post($this->url($plan, '/population'), ['file' => $bad])->assertSessionHasErrors('file');
    }

    public function test_non_statistical_attributes_and_judgemental_plans(): void
    {
        $this->approvedMateriality();
        // Judgemental selection is always non-statistical, needs a rationale, and items are chosen with reasons.
        $j = $this->makePlan(['title' => 'Large debtors review', 'method' => 'judgemental', 'approach' => 'statistical']);
        $this->assertSame('non_statistical', $j->approach->value);
        $this->actingAs($this->ctx['senior'])->post($this->url($j, '/population'), ['file' => $this->population()])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->put($this->url($j, '/parameters'), ['tolerable_misstatement' => '5,000'])->assertSessionHasErrors('method_rationale');
        $this->actingAs($this->ctx['senior'])->put($this->url($j, '/parameters'), ['tolerable_misstatement' => '5,000', 'method_rationale' => 'Largest balances and those over 90 days.'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->post($this->url($j, '/submit'))->assertSessionHasNoErrors();
        $this->decide($j->approvals()->pending()->sole(), $this->ctx['partner'])->assertSessionHasNoErrors();
        $big = $j->populationItems()->where('item_ref', 'INV-0000')->value('id');
        $this->actingAs($this->ctx['senior'])->post($this->url($j, '/select'), ['items' => [$big => '']])->assertSessionHasErrors('items');
        $this->actingAs($this->ctx['senior'])->post($this->url($j, '/select'), ['items' => [$big => 'Largest balance']])->assertSessionHasNoErrors();
        $this->assertSame(['judgemental', 'Largest balance'], [SampleItem::sole()->selection_reason, SampleItem::sole()->selection_note]);

        // Non-statistical systematic: needs the firm's assurance factor and methodology rationale.
        $n = $this->makePlan(['title' => 'Systematic debtors', 'method' => 'systematic', 'approach' => 'non_statistical']);
        $this->actingAs($this->ctx['senior'])->post($this->url($n, '/population'), ['file' => $this->population()])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->put($this->url($n, '/parameters'), ['tolerable_misstatement' => '5,000', 'key_item_threshold' => '10,000'])->assertSessionHasErrors('assurance_factor');
        $this->actingAs($this->ctx['senior'])->put($this->url($n, '/parameters'), ['tolerable_misstatement' => '5,000', 'key_item_threshold' => '10,000', 'assurance_factor' => '1.2', 'method_rationale' => 'Firm methodology table, moderate risk.'])->assertSessionHasNoErrors();
        $this->assertSame(15, $n->fresh()->sample_size); // ceil(60,000 × 1.2 / 5,000) = 15

        // Attributes: 5% tolerable, 0% expected, 95% → 60 (Poisson); population smaller → capped.
        $a = $this->makePlan(['title' => 'Credit approval control', 'test_type' => 'attributes', 'method' => 'random', 'approach' => 'statistical']);
        $this->actingAs($this->ctx['senior'])->post($this->url($a, '/population'), ['file' => $this->population(200, '100.00', '60,000.00')])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['senior'])->put($this->url($a, '/parameters'), ['tolerable_deviation_rate' => '5', 'expected_deviation_rate' => '0', 'confidence_level' => '95'])->assertSessionHasNoErrors();
        $this->assertSame(60, $a->fresh()->sample_size);

        // MUS cannot be an attributes test; outsiders and staff cannot manage sampling.
        $this->actingAs($this->ctx['senior'])->post($this->base().'/sampling', ['title' => 'x', 'objective' => 'x', 'test_type' => 'attributes', 'method' => 'mus'])->assertSessionHasErrors('method');
        $this->actingAs($this->ctx['staff'])->post($this->base().'/sampling', ['title' => 'x', 'objective' => 'x', 'test_type' => 'monetary', 'method' => 'random'])->assertForbidden();
        $this->actingAs($this->staff($this->ctx['firm'], FirmRole::Manager))->get($this->url($a))->assertForbidden();
    }
}
