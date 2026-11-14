<?php

namespace Database\Seeders;

use App\Models\MethodologyLibrary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the AUDIRA methodology template (platform-wide, read-only to firms). Idempotent: an
 * existing template is left untouched so firms' copies keep pointing at the version they took.
 * Must run in system mode (DatabaseSeeder wraps it).
 */
class MethodologySeeder extends Seeder
{
    public const CODE = 'audira-v1';

    public function run(): void
    {
        if (MethodologyLibrary::whereNull('firm_id')->where('code', self::CODE)->exists()) {
            return;
        }
        $data = require database_path('seeders/data/audira_methodology.php');

        DB::transaction(function () use ($data) {
            $library = MethodologyLibrary::create([
                'code' => self::CODE,
                'name' => 'AUDIRA Audit Methodology',
                'description' => "AUDIRA's starter methodology for audits of Malaysian companies and strata bodies, written in AUDIRA's own words. Each step names the ISA it responds to; add paragraph references from your firm's licensed copy of the standards. Make a firm copy to adapt it. Assertion codes: E existence, O occurrence, C completeness, A accuracy, CO cut-off, CL classification, R rights and obligations, V valuation and allocation, P presentation.",
                'version' => 1,
                'status' => 'active',
            ]);
            foreach ($data as $i => [$prefix, $stage, $scope, $target, $title, $objective, $steps]) {
                $programme = $library->programmes()->create([
                    'stage' => $stage,
                    'scope' => $scope,
                    'fsli_codes' => $scope === 'fsli' ? $target : [],
                    'fsli_classification' => $scope === 'classification' ? $target : null,
                    'ref_prefix' => $prefix,
                    'title' => $title,
                    'objective' => $objective,
                    'sort_order' => ($i + 1) * 10,
                ]);
                foreach ($steps as $j => [$procedure, $assertions, $isa, $guidance]) {
                    $programme->steps()->create([
                        'position' => ($j + 1) * 10,
                        'ref' => sprintf('%02d', $j + 1),
                        'procedure' => $procedure,
                        'assertions' => $assertions,
                        'isa_refs' => $isa,
                        'guidance' => $guidance,
                    ]);
                }
            }
        });
    }
}
