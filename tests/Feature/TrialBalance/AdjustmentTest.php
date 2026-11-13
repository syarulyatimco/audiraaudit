<?php

namespace Tests\Feature\TrialBalance;

use App\Enums\AdjustmentStatus;
use App\Models\Adjustment;
use App\Services\TrialBalance\LeadScheduleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AdjustmentTest extends TrialBalanceTestCase
{
    private function draft(array $lines = null, $user = null): Adjustment
    {
        $lines ??= [
            ['account_code' => '1200', 'debit' => '', 'credit' => '4,500.00', 'description' => 'Write down obsolete stock'],
            ['account_code' => '5000', 'debit' => '4,500.00', 'credit' => ''],
        ];
        $this->actingAs($user ?? $this->ctx['staff'])->post($this->base().'/adjustments', ['type' => 'audit', 'description' => 'Inventory NRV write-down per F4', 'lines' => $lines])->assertSessionHasNoErrors();

        return Adjustment::latest('id')->first();
    }

    /** Without approved materiality every adjustment is material: request and obtain partner approval. */
    private function approve(Adjustment $adj, $requester): void
    {
        $this->actingAs($requester)->post($this->base()."/adjustments/{$adj->uuid}/approval", ['comment' => 'Please approve'])->assertSessionHasNoErrors();
        $approval = $adj->approvals()->pending()->sole();
        $this->actingAs($this->ctx['partner'])->post("/approvals/{$approval->uuid}/decide", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $adj->fresh()->approval_status->value);
    }

    public function test_requires_current_tb_and_balanced_valid_lines(): void
    {
        $this->actingAs($this->ctx['staff'])->post($this->base().'/adjustments', ['type' => 'audit', 'description' => 'x', 'lines' => [['account_code' => '1', 'debit' => '1'], ['account_code' => '2', 'credit' => '1']]])
            ->assertSessionHasErrors('lines');
        $this->importTb();

        $this->actingAs($this->ctx['staff'])->post($this->base().'/adjustments', ['type' => 'audit', 'description' => 'x', 'lines' => [
            ['account_code' => '1200', 'credit' => '100'], ['account_code' => '5000', 'debit' => '99.99'],
        ]])->assertSessionHasErrors('lines');
        $this->actingAs($this->ctx['staff'])->post($this->base().'/adjustments', ['type' => 'audit', 'description' => 'x', 'lines' => [
            ['account_code' => 'ZZZ', 'credit' => '100'], ['account_code' => '5000', 'debit' => '100'],
        ]])->assertSessionHasErrors('lines');
        $this->assertSame(0, Adjustment::count());

        $adj = $this->draft();
        $this->assertSame('AJE-001', $adj->number);
        $this->assertSame(450000, $adj->totalDebitSen());
    }

    public function test_preparer_cannot_post_and_posting_flows_to_lead_schedules(): void
    {
        $this->importTb();
        $this->mapAll();
        $adj = $this->draft(null, $this->ctx['manager']);
        $this->actingAs($this->ctx['partner'])->post($this->base()."/adjustments/{$adj->uuid}/post")->assertSessionHasErrors('adjustment');
        $this->approve($adj, $this->ctx['manager']);

        $this->actingAs($this->ctx['manager'])->post($this->base()."/adjustments/{$adj->uuid}/post")->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/adjustments/{$adj->uuid}/post")->assertForbidden();
        $this->actingAs($this->ctx['partner'])->post($this->base()."/adjustments/{$adj->uuid}/post")->assertSessionHasNoErrors();
        $this->assertSame(AdjustmentStatus::Posted, $adj->fresh()->status);

        $data = app(LeadScheduleService::class)->build($this->ctx['engagement']);
        $inv = $data['fslis'][$this->fsliId('INV')];
        $this->assertSame(6000000, $inv['cy']);
        $this->assertSame(-450000, $inv['adj']);
        $this->assertSame(5550000, $inv['cy_adj']);
        $this->assertSame(14550000, $data['cy']['profit']);
        $this->assertSame(15000000, $data['cy_unadjusted']['profit']);
        $this->assertTrue($data['checks']['tb_balances']);

        // DB refuses changes to a posted adjustment's lines and self-posting
        try {
            DB::table('adjustment_lines')->where('adjustment_id', $adj->id)->update(['debit_sen' => 1]);
            $this->fail('Posted lines must be immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_db_prevents_self_posting(): void
    {
        $this->importTb();
        $adj = $this->draft();
        $this->expectException(QueryException::class);
        DB::table('adjustments')->where('id', $adj->id)->update(['status' => 'posted', 'posted_by' => $adj->prepared_by]);
    }

    public function test_void_posted_reverses_effect_and_lead_paper_signoffs_are_invalidated(): void
    {
        $this->importTb();
        $this->mapAll();
        ['manager' => $manager, 'senior' => $senior, 'partner' => $partner] = $this->ctx;

        // lead paper for inventories, prepared and reviewed
        $this->actingAs($senior)->post($this->base().'/lead-schedules/'.$this->fsliId('INV').'/paper')->assertRedirect();
        $wp = $this->ctx['engagement']->workingPapers()->where('reference', 'L-INV')->sole();
        $this->actingAs($senior)->put($this->base()."/working-papers/{$wp->uuid}", ['title' => $wp->title, 'conclusion' => 'Inventories agreed to count and NRV review.']);
        $this->actingAs($senior)->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'preparer'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($this->base()."/working-papers/{$wp->uuid}/signoff", ['role' => 'reviewer'])->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $wp->fresh()->status->value);

        $adj = $this->draft();
        $this->approve($adj, $this->ctx['staff']);
        $this->actingAs($manager)->post($this->base()."/adjustments/{$adj->uuid}/post")->assertSessionHasNoErrors();
        $this->assertSame('draft', $wp->fresh()->status->value, 'Figures changed under the signed lead schedule');

        $this->actingAs($senior)->post($this->base()."/adjustments/{$adj->uuid}/void", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($partner)->post($this->base()."/adjustments/{$adj->uuid}/void", ['reason' => 'Client provided updated NRV evidence'])->assertSessionHasNoErrors();
        $this->assertSame(0, app(LeadScheduleService::class)->build($this->ctx['engagement'])['fslis'][$this->fsliId('INV')]['adj']);

        // pages render
        $this->actingAs($senior)->get($this->base().'/adjustments')->assertOk()->assertSee('AJE-001');
        $this->actingAs($senior)->get($this->base()."/adjustments/{$adj->uuid}")->assertOk()->assertSee('Client provided updated NRV evidence');
        $this->actingAs($senior)->get($this->base().'/adjustments/create')->assertOk();
    }

    public function test_draft_edit_and_numbering_per_type(): void
    {
        $this->importTb();
        $a1 = $this->draft();
        $this->actingAs($this->ctx['staff'])->post($this->base().'/adjustments', ['type' => 'reclassification', 'description' => 'Reclass overdraft', 'lines' => [
            ['account_code' => '1000', 'debit' => '5000'], ['account_code' => '1010', 'credit' => '5000'],
        ]]);
        $this->assertSame('RJE-001', Adjustment::latest('id')->first()->number);

        $this->actingAs($this->ctx['staff'])->put($this->base()."/adjustments/{$a1->uuid}", ['description' => 'Revised', 'lines' => [
            ['account_code' => '1200', 'credit' => '5,000'], ['account_code' => '5000', 'debit' => '5,000'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(500000, $a1->fresh()->load('lines')->totalDebitSen());
        $this->actingAs($this->ctx['staff'])->get($this->base()."/adjustments/{$a1->uuid}/edit")->assertOk();
    }
}
