<?php

namespace Database\Seeders;

use App\Models\LibraryFramework;
use Illuminate\Database\Seeder;

/**
 * Seeds library FRAMEWORKS only (structure). Standards and paragraph text must be loaded by the
 * Superadmin from authorised, licensed source material — never generated (CLAUDE.md §4).
 */
class LibrarySeeder extends Seeder
{
    public function run(): void
    {
        $frameworks = [
            ['mfrs', 'Malaysian Financial Reporting Standards (MFRS)', 'reporting', 'Malaysian Accounting Standards Board (MASB)'],
            ['mpers', 'Malaysian Private Entities Reporting Standard (MPERS)', 'reporting', 'Malaysian Accounting Standards Board (MASB)'],
            ['mpsas', 'Malaysian Public Sector Accounting Standards (MPSAS)', 'reporting', 'Accountant General\'s Department of Malaysia'],
            ['isa', 'International Standards on Auditing (approved standards on auditing in Malaysia)', 'auditing', 'IAASB, as adopted by the Malaysian Institute of Accountants (MIA)'],
            ['isqm', 'International Standards on Quality Management', 'quality', 'IAASB, as adopted by the Malaysian Institute of Accountants (MIA)'],
            ['ca2016', 'Companies Act 2016', 'legislation', 'Parliament of Malaysia'],
            ['sma2013', 'Strata Management Act 2013 (JMB / MC)', 'legislation', 'Parliament of Malaysia'],
            ['skm', 'Cooperative sector requirements (SKM)', 'regulatory', 'Suruhanjaya Koperasi Malaysia'],
        ];
        foreach ($frameworks as $i => [$code, $name, $category, $issuer]) {
            LibraryFramework::firstOrCreate(['code' => $code], ['name' => $name, 'category' => $category, 'issuer' => $issuer, 'sort_order' => $i + 1]);
        }
    }
}
