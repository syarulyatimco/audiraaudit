<?php

namespace Tests\Feature\TrialBalance;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Models\Engagement;
use App\Models\TbImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class TrialBalanceTestCase extends TestCase
{
    protected array $ctx;

    /** A small balanced TB. Debits 451,000.00 = credits 451,000.00 */
    protected const CY = [
        ['Account Code', 'Account Name', 'Debit', 'Credit'],
        ['1000', 'Cash at Bank - Maybank', '45,000.00', ''],
        ['1010', 'Bank overdraft - CIMB', '', '5,000.00'],
        ['1100', 'Trade Debtors Control', '80,000.00', ''],
        ['1200', 'Stock-in-trade', '60,000.00', ''],
        ['1500', 'Motor Vehicles', '120,000.00', ''],
        ['3000', 'Share Capital', '', '100,000.00'],
        ['3100', 'Retained Earnings', '', '50,000.00'],
        ['4000', 'Sales', '', '296,000.00'],
        ['5000', 'Purchases', '120,000.00', ''],
        ['6000', 'Salaries and wages', '26,000.00', ''],
        ['', 'TOTAL', '451,000.00', '451,000.00'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(\Database\Seeders\FsliTemplateSeeder::class);
        [$firm, $partner] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $senior = $this->staff($firm, FirmRole::Senior);
        $staff = $this->staff($firm);
        $client = $this->client($partner);
        $engagement = $this->engagement($client, $partner, [[$manager, EngagementRole::Manager], [$senior, EngagementRole::Senior], [$staff, EngagementRole::Staff]]);
        $this->ctx = compact('firm', 'partner', 'manager', 'senior', 'staff', 'client', 'engagement');
    }

    protected function csv(array $rows, string $name = 'tb.csv'): UploadedFile
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        rewind($h);

        return UploadedFile::fake()->createWithContent($name, stream_get_contents($h));
    }

    protected function base(?Engagement $e = null): string
    {
        return '/engagements/'.($e ?? $this->ctx['engagement'])->uuid;
    }

    /** Upload + activate with auto-detected columns. */
    protected function importTb(array $rows = self::CY, string $period = 'current', ?Engagement $e = null, $user = null): TbImport
    {
        $user ??= $this->ctx['senior'];
        $e ??= $this->ctx['engagement'];
        $this->actingAs($user)->post($this->base($e).'/trial-balance', ['period' => $period, 'file' => $this->csv($rows)])->assertSessionHasNoErrors();
        $import = $e->tbImports()->latest('id')->first();
        $this->actingAs($user)->post($this->base($e)."/trial-balance/imports/{$import->uuid}", $import->column_map)->assertSessionHasNoErrors();

        return $import->fresh();
    }

    protected function fsliId(string $code, ?Engagement $e = null): int
    {
        return ($e ?? $this->ctx['engagement'])->fslis()->where('code', $code)->value('id');
    }

    protected function mapAll(?Engagement $e = null): void
    {
        $map = ['1000' => 'CASH', '1010' => 'BORR-C', '1100' => 'TR', '1200' => 'INV', '1500' => 'PPE', '3000' => 'SC', '3100' => 'RE', '4000' => 'REV', '5000' => 'COS', '6000' => 'ADMIN'];
        $this->actingAs($this->ctx['staff'])->put($this->base($e).'/mapping', ['map' => array_map(fn ($c) => $this->fsliId($c, $e), $map)])->assertSessionHasNoErrors();
    }
}
