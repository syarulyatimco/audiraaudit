<?php

namespace Tests\Feature\Ledger;

use App\Models\GlImport;
use App\Models\JetTest;
use App\Models\SamplingPlan;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TrialBalance\TrialBalanceTestCase;

class GeneralLedgerTest extends TrialBalanceTestCase
{
    /**
     * A GL in a report-style export (title lines, DR/CR columns, Balance B/F rows) whose double
     * entry agrees exactly to the test TB. Salaries are posted on a Sunday; the overdraft entry is a
     * round year-end journal by an infrequent user.
     */
    private function glRows(int $salaries = 26000): array
    {
        $s = number_format($salaries, 2);

        return [
            ['SINAR MAJU SDN BHD'], ['General Ledger Listing 01/01/2025 - 31/12/2025'], [],
            ['Doc Date', 'Acc No', 'Account Name', 'Doc No', 'Description', 'DR', 'CR', 'Journal', 'User'],
            ['01/01/2025', '1000', 'Cash at Bank', '', 'Balance B/F', '30,000.00', '', '', ''],
            ['01/01/2025', '1010', 'Bank overdraft', '', 'Balance B/F', '', '60,000.00', '', ''],
            ['01/01/2025', '1200', 'Stock', '', 'Balance B/F', '60,000.00', '', '', ''],
            ['01/01/2025', '1500', 'Motor Vehicles', '', 'Balance B/F', '120,000.00', '', '', ''],
            ['01/01/2025', '3000', 'Share Capital', '', 'Balance B/F', '', '100,000.00', '', ''],
            ['01/01/2025', '3100', 'Retained Earnings', '', 'Balance B/F', '', '50,000.00', '', ''],
            ['30/06/2025', '1100', 'Trade Debtors', 'IV-0001', 'Sales to customers', '296,000.00', '', 'SL', 'aminah'],
            ['30/06/2025', '4000', 'Sales', 'IV-0001', 'Sales to customers', '', '296,000.00', 'SL', 'aminah'],
            ['15/08/2025', '1000', 'Cash at Bank', 'OR-0101', 'Receipts from debtors', '216,000.00', '', 'OR', 'aminah'],
            ['15/08/2025', '1100', 'Trade Debtors', 'OR-0101', 'Receipts from debtors', '', '216,000.00', 'OR', 'aminah'],
            ['10/09/2025', '5000', 'Purchases', 'PI-0300', 'Purchases from suppliers', '120,000.00', '', 'PI', 'aminah'],
            ['10/09/2025', '1000', 'Cash at Bank', 'PV-0300', 'Payment to suppliers', '', '120,000.00', 'PV', 'aminah'],
            ['30/11/2025', '6000', 'Salaries', 'PV-0411', 'November salaries', $s, '', 'PV', 'aminah'],
            ['30/11/2025', '1000', 'Cash at Bank', 'PV-0411', 'November salaries', '', $s, 'PV', 'aminah'],
            ['31/12/2025', '1010', 'Bank overdraft', 'JV-0099', 'Adjustment - reclass overdraft', '55,000.00', '', 'JV', 'tempuser'],
            ['31/12/2025', '1000', 'Cash at Bank', 'JV-0099', 'Adjustment - reclass overdraft', '', '55,000.00', 'JV', 'tempuser'],
            ['', '', '', '', 'Grand Total', '923,000.00', '923,000.00', '', ''],
        ];
    }

    private function importGl(array $rows): GlImport
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/general-ledger', ['file' => $this->csv($rows)])->assertSessionHasNoErrors();
        $import = GlImport::latest('id')->first();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/general-ledger/imports/{$import->uuid}", ['header_row' => $import->header_row] + $import->column_map)->assertSessionHasNoErrors();

        return $import->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importTb();
        $this->mapAll();
    }

    public function test_gl_import_detects_columns_reconciles_to_the_tb_and_is_immutable(): void
    {
        $this->actingAs($this->ctx['staff'])->post($this->base().'/general-ledger', ['file' => $this->csv($this->glRows())])->assertSessionHasErrors('file');
        $this->actingAs($this->ctx['senior'])->post($this->base().'/general-ledger', ['file' => $this->csv($this->glRows())])->assertSessionHasNoErrors();
        $pending = GlImport::sole();
        $this->assertSame(4, $pending->header_row, 'title lines skipped');
        $this->assertSame(['date' => 0, 'account' => 1, 'name' => 2, 'reference' => 3, 'description' => 4, 'debit' => 5, 'credit' => 6, 'journal' => 7, 'user' => 8], collect($pending->column_map)->sortBy(fn ($v) => $v)->all());
        $this->actingAs($this->ctx['senior'])->get($this->base()."/general-ledger/imports/{$pending->uuid}")->assertOk()->assertSee('Check the columns');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/general-ledger/imports/{$pending->uuid}", ['header_row' => 4, 'account' => 1, 'debit' => 5, 'credit' => 6])->assertSessionHasErrors('map'); // no date column

        $gl = $this->importGl($this->glRows());
        $this->assertSame(['active', 16, 92_300_000, 92_300_000], [$gl->status, $gl->line_count, (int) $gl->debit_sen, (int) $gl->credit_sen]);
        $this->assertSame(['2025-06-30', '2025-12-31'], [$gl->first_date->toDateString(), $gl->last_date->toDateString()]);
        $this->assertSame(0, $gl->reconciliation['differences']);
        $this->assertTrue($gl->reconciliation['balanced']);
        $cash = collect($gl->reconciliation['accounts'])->firstWhere('code', '1000');
        $this->assertSame([3_000_000, 1_500_000, 4_500_000, 4_500_000, 'gl'], [$cash['opening'], $cash['movement'], $cash['gl_closing'], $cash['tb_closing'], $cash['opening_source']]);
        $this->assertSame('nil (P&L)', collect($gl->reconciliation['accounts'])->firstWhere('code', '4000')['opening_source']);
        $this->actingAs($this->ctx['staff'])->get($this->base().'/general-ledger')->assertOk()->assertSee('All agreed');

        // Drill-down from the lead schedule.
        $this->actingAs($this->ctx['staff'])->get($this->base().'/lead-schedules/'.$this->fsliId('CASH'))->assertOk()->assertSee('GL postings');
        $this->actingAs($this->ctx['staff'])->get($this->base().'/general-ledger/postings?fsli=CASH')->assertOk()->assertSee('Receipts from debtors')->assertSee('b/f')->assertSee('5 postings', false);
        $this->actingAs($this->ctx['staff'])->get($this->base().'/general-ledger/postings?account=1000&q=salaries')->assertOk()->assertSee('November salaries')->assertDontSee('Receipts from debtors');

        // Lines are immutable at database level.
        try {
            DB::transaction(fn () => DB::table('gl_lines')->where('gl_import_id', $gl->id)->limit(1)->update(['amount_sen' => 0]));
            $this->fail('GL lines must be immutable');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // A re-import with a wrong salaries figure supersedes the first and shows the differences.
        $gl2 = $this->importGl($this->glRows(25000));
        $this->assertSame('superseded', $gl->fresh()->status);
        $this->assertSame(2, $gl2->reconciliation['differences']);
        $this->assertSame(['1000', '6000'], collect($gl2->reconciliation['accounts'])->where('status', 'difference')->pluck('code')->sort()->values()->all());
        $this->actingAs($this->ctx['staff'])->get($this->base().'/general-ledger')->assertOk()->assertSee('2 to explain');

        // Bad rows are reported with their row numbers.
        $bad = $this->glRows();
        $bad[10][0] = '31/02/2025';
        $this->actingAs($this->ctx['senior'])->post($this->base().'/general-ledger', ['file' => $this->csv($bad)])->assertSessionHasNoErrors();
        $p = GlImport::latest('id')->first();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/general-ledger/imports/{$p->uuid}", ['header_row' => 4] + $p->column_map)
            ->assertSessionHasErrors(['map' => 'Row 11: "31/02/2025" is not a date.']);

        // The source can be a document already on the engagement (e.g. a client portal upload).
        $this->actingAs($this->ctx['senior'])->post($this->base().'/general-ledger', ['document' => $gl->document->uuid])->assertSessionHasNoErrors();
        $this->assertSame($gl->document_id, GlImport::latest('id')->first()->document_id);
    }

    public function test_journal_entry_testing_and_gl_sampling(): void
    {
        $this->importGl($this->glRows());
        $url = $this->base().'/general-ledger/journal-testing';

        // Weekend postings: the salaries entry (Sunday 30 Nov 2025), both lines.
        $this->actingAs($this->ctx['staff'])->get("{$url}?weekend=1")->assertOk()->assertSee('2 entries selected')->assertSee('November salaries')->assertDontSee('Receipts from debtors');
        // At least two of: near year end, keyword, round amount, infrequent user → only the overdraft journal.
        $q = 'year_end_days=3&keywords=adjust,reverse&round_rm=1000&rare_users_max=2&min_matches=3';
        $this->actingAs($this->ctx['staff'])->get("{$url}?{$q}")->assertOk()->assertSee('2 entries selected')->assertSee('Adjustment - reclass overdraft')->assertDontSee('November salaries');
        $this->actingAs($this->ctx['staff'])->get("{$url}?threshold_rm=200,000")->assertOk()->assertSee('4 entries selected');

        // Save the selection as a test; staff record results; a manager concludes.
        parse_str($q, $criteria);
        $this->actingAs($this->ctx['staff'])->post($url, $criteria + ['title' => 'Override'])->assertSessionHasErrors('jet');
        $this->actingAs($this->ctx['senior'])->post($url, ['title' => 'Nothing'])->assertSessionHasErrors('jet');
        $this->actingAs($this->ctx['senior'])->post($url, $criteria + ['title' => 'Year-end journals'])->assertSessionHasNoErrors();
        $test = JetTest::sole();
        $this->assertSame(['JET-01', 2], [$test->reference, $test->items()->count()]);
        $this->assertEqualsCanonicalizing(['year_end', 'round', 'rare_users', 'keywords'], $test->items()->first()->reasons);

        [$a, $b] = $test->items()->get()->all();
        $item = fn ($i) => $this->base()."/general-ledger/journal-tests/{$test->uuid}/items/{$i->id}";
        $this->actingAs($this->ctx['staff'])->post($item($a), ['result' => 'exception'])->assertSessionHasErrors('note');
        $this->actingAs($this->ctx['staff'])->post($item($a), ['result' => 'no_exception', 'note' => 'Board-approved reclassification; inspected memo.'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['manager'])->post($this->base()."/general-ledger/journal-tests/{$test->uuid}/conclude", ['conclusion' => 'Done'])->assertSessionHasErrors('conclusion');
        $this->actingAs($this->ctx['staff'])->post($item($b), ['result' => 'no_exception'])->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['staff'])->post($this->base()."/general-ledger/journal-tests/{$test->uuid}/conclude", ['conclusion' => 'x'])->assertSessionHasErrors('jet');
        $this->actingAs($this->ctx['manager'])->post($this->base()."/general-ledger/journal-tests/{$test->uuid}/conclude", ['conclusion' => 'No indication of management override in the entries tested.'])->assertSessionHasNoErrors();
        $this->assertSame('concluded', $test->fresh()->status);
        $this->actingAs($this->ctx['staff'])->post($item($b), ['result' => 'exception', 'note' => 'x'])->assertSessionHasErrors('item');
        $this->actingAs($this->ctx['staff'])->get($this->base()."/general-ledger/journal-tests/{$test->uuid}")->assertOk()->assertSee('No indication of management override');

        // Sampling population straight from the GL.
        $this->actingAs($this->ctx['senior'])->post($this->base().'/sampling', ['title' => 'Cash payments', 'objective' => 'Occurrence', 'test_type' => 'monetary', 'method' => 'random', 'approach' => 'non_statistical', 'engagement_fsli_id' => $this->fsliId('CASH')])->assertSessionHasNoErrors();
        $plan = SamplingPlan::sole();
        $this->actingAs($this->ctx['senior'])->get($this->base()."/sampling/{$plan->uuid}")->assertOk()->assertSee('Load from general ledger');
        $this->actingAs($this->ctx['senior'])->post($this->base()."/sampling/{$plan->uuid}/population/gl", ['fsli' => 'CASH', 'side' => 'credits'])->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame([3, 20_100_000], [$plan->population_count, (int) $plan->population_value_sen]); // 120,000 + 26,000 + 55,000
        $this->assertStringStartsWith('General ledger: 1000', $plan->population_source);
        $this->assertNotNull($plan->population_sha256);
    }
}
