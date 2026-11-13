<?php

namespace Tests\Feature\TrialBalance;

use App\Enums\TbImportStatus;
use App\Models\Document;
use App\Models\TbImport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Unit\SpreadsheetReaderTest;

class TrialBalanceImportTest extends TrialBalanceTestCase
{
    public function test_csv_import_detects_columns_validates_and_activates(): void
    {
        $senior = $this->ctx['senior'];
        $this->actingAs($senior)->post($this->base().'/trial-balance', ['period' => 'current', 'file' => $this->csv(self::CY)])->assertRedirect();
        $import = TbImport::sole();
        $this->assertEquals(['header_row' => 1, 'mode' => 'debit_credit', 'code' => 0, 'name' => 1, 'debit' => 2, 'credit' => 3], $import->column_map);
        $this->assertTrue(Document::whereKey($import->document_id)->exists(), 'Source file kept as evidence');
        $this->actingAs($senior)->get($this->base()."/trial-balance/imports/{$import->uuid}")->assertOk()->assertSee('Cash at Bank - Maybank');

        $this->actingAs($senior)->post($this->base()."/trial-balance/imports/{$import->uuid}", $import->column_map)->assertRedirect();
        $import->refresh();

        $this->assertSame(TbImportStatus::Active, $import->status);
        $this->assertSame(10, $import->row_count);
        $this->assertSame(45100000, $import->total_debit_sen);
        $this->assertSame($import->total_debit_sen, $import->total_credit_sen);
        $this->assertCount(1, $import->warnings); // TOTAL row skipped
        $this->assertSame(-500000, $import->lines()->where('account_code', '1010')->value('balance_sen'));
        $this->assertSame(33, $this->ctx['engagement']->fslis()->count(), 'FSLIs initialised from default template');
    }

    public function test_xlsx_with_single_balance_column(): void
    {
        $path = SpreadsheetReaderTest::makeXlsx([
            ['ABC Sdn Bhd — Trial balance as at 30.6.2025', '', ''],
            ['GL code', 'Description', 'Balance'],
            ['1000', 'Bank', '1500.5'],
            ['4000', 'Revenue', '-1500.5'],
        ]);
        $file = new \Illuminate\Http\UploadedFile($path, 'tb.xlsx', null, null, true);
        $this->actingAs($this->ctx['senior'])->post($this->base().'/trial-balance', ['period' => 'current', 'file' => $file])->assertSessionHasNoErrors();
        $import = TbImport::sole();
        $this->assertSame(2, $import->column_map['header_row']);
        $this->assertSame('balance', $import->column_map['mode']);

        $this->actingAs($this->ctx['senior'])->post($this->base()."/trial-balance/imports/{$import->uuid}", $import->column_map)->assertSessionHasNoErrors();
        $this->assertSame(150050, $import->fresh()->total_debit_sen);
    }

    public function test_unbalanced_duplicate_and_invalid_rows_are_rejected(): void
    {
        $rows = self::CY;
        $rows[1][2] = '45,000.01';
        $rows[] = ['1100', 'Duplicate debtors', '1.00', ''];
        $rows[] = ['9999', 'Bad amount', 'abc', ''];
        $this->actingAs($this->ctx['senior'])->post($this->base().'/trial-balance', ['period' => 'current', 'file' => $this->csv($rows)]);
        $import = TbImport::sole();

        $response = $this->actingAs($this->ctx['senior'])->post($this->base()."/trial-balance/imports/{$import->uuid}", $import->column_map);
        $response->assertSessionHasErrors('import');
        $errors = implode(' ', session('errors')->get('import'));
        $this->assertStringContainsString('also appears on row', $errors);
        $this->assertStringContainsString('not a valid amount', $errors);
        $this->assertSame(TbImportStatus::Pending, $import->fresh()->status);

        // fix: only imbalance left → balance check message
        $rows2 = self::CY;
        $rows2[1][2] = '45,000.01';
        $this->actingAs($this->ctx['senior'])->post($this->base().'/trial-balance', ['period' => 'current', 'file' => $this->csv($rows2)]);
        $i2 = TbImport::latest('id')->first();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/trial-balance/imports/{$i2->uuid}", $i2->column_map)->assertSessionHasErrors('import');
        $this->assertStringContainsString('does not balance', implode(' ', session('errors')->get('import')));
    }

    public function test_reimport_supersedes_and_keeps_mappings(): void
    {
        $first = $this->importTb();
        $this->mapAll();
        $second = $this->importTb();

        $this->assertSame(TbImportStatus::Superseded, $first->fresh()->status);
        $this->assertSame(TbImportStatus::Active, $second->status);
        $this->assertSame(10, $this->ctx['engagement']->accountMappings()->count());
    }

    public function test_permissions_and_tenancy(): void
    {
        ['staff' => $staff, 'firm' => $firm] = $this->ctx;
        $this->actingAs($staff)->post($this->base().'/trial-balance', ['period' => 'current', 'file' => $this->csv(self::CY)])->assertForbidden();
        $outsider = $this->staff($firm);
        $this->actingAs($outsider)->get($this->base().'/trial-balance')->assertForbidden();
        [, $foreign] = $this->makeFirm();
        $this->actingAs($foreign)->get($this->base().'/lead-schedules')->assertNotFound();
        $this->actingAs($staff)->get($this->base().'/trial-balance')->assertOk();
    }

    public function test_imported_lines_are_immutable_and_rls_protected(): void
    {
        $import = $this->importTb();
        [$other] = $this->makeFirm();
        $this->tenant()->set($other->id);
        $this->assertSame(0, DB::table('tb_lines')->count());
        $this->tenant()->set($this->ctx['firm']->id);

        $this->expectException(QueryException::class);
        DB::table('tb_lines')->where('tb_import_id', $import->id)->update(['balance_sen' => 0, 'debit_sen' => 0, 'credit_sen' => 0]);
    }

    public function test_disguised_file_rejected(): void
    {
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('tb.xlsx', 'not a zip');
        $this->actingAs($this->ctx['senior'])->post($this->base().'/trial-balance', ['period' => 'current', 'file' => $file])->assertSessionHasErrors('file');
    }
}
