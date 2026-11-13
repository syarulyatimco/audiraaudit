<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\MaterialityAssessment;
use App\Models\SamplingPlan;
use Illuminate\Http\UploadedFile;

/**
 * Builds a selected MUS sample on trade receivables through the real HTTP flow (requires the
 * TrialBalanceTestCase context with TB imported and mapped): one 20,000.00 key item and
 * 100 × 600.00 items, TM 5,000.00, 95% confidence → key item + 36 sampled items.
 */
trait BuildsSamples
{
    protected function approveMaterialityViaRoutes(): MaterialityAssessment
    {
        $this->actingAs($this->ctx['senior'])->put($this->base().'/planning/materiality', ['benchmark' => 'profit_before_tax', 'benchmark_basis' => 'current_year',
            'om_percentage' => '5', 'pm_percentage' => '75', 'ct_percentage' => '5', 'rationale' => 'Profit before tax is the focus of users.'])->assertSessionHasNoErrors();
        $m = $this->ctx['engagement']->materialityAssessments()->latest('id')->first();
        $this->actingAs($this->ctx['senior'])->post($this->base()."/planning/materiality/{$m->uuid}/submit")->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['partner'])->post('/approvals/'.$m->approvals()->pending()->sole()->uuid.'/decide', ['decision' => 'approve'])->assertSessionHasNoErrors();

        return $m->fresh();
    }

    protected function selectedSample(): SamplingPlan
    {
        $s = $this->ctx['senior'];
        $this->actingAs($s)->post($this->base().'/sampling', ['title' => 'Debtors confirmation', 'objective' => 'Existence of trade receivables.', 'test_type' => 'monetary',
            'method' => 'mus', 'engagement_fsli_id' => $this->fsliId('TR')])->assertSessionHasNoErrors();
        $plan = SamplingPlan::latest('id')->first();
        $rows = "Reference,Customer,Amount\nINV-0000,Big Customer,\"20,000.00\"\n";
        for ($i = 1; $i <= 100; $i++) {
            $rows .= sprintf("INV-%04d,Customer %d,600.00\n", $i, $i);
        }
        $url = $this->base()."/sampling/{$plan->uuid}";
        $this->actingAs($s)->post("{$url}/population", ['file' => UploadedFile::fake()->createWithContent('d.csv', $rows)])->assertSessionHasNoErrors();
        $this->actingAs($s)->put("{$url}/parameters", ['tolerable_misstatement' => '5,000.00', 'expected_misstatement' => '0', 'key_item_threshold' => '10,000', 'confidence_level' => '95'])->assertSessionHasNoErrors();
        $this->actingAs($s)->post("{$url}/submit")->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['manager'])->post('/approvals/'.$plan->approvals()->pending()->sole()->uuid.'/decide', ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($s)->post("{$url}/select")->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    protected function uploadDocument(string $name, string $content): Document
    {
        $this->actingAs($this->ctx['senior'])->post($this->base().'/documents', ['file' => UploadedFile::fake()->createWithContent($name, $content), 'category' => 'evidence'])->assertSessionHasNoErrors();

        return Document::latest('id')->first();
    }
}
