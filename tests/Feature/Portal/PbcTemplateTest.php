<?php

namespace Tests\Feature\Portal;

use App\Models\PbcItem;
use Tests\Feature\TrialBalance\TrialBalanceTestCase;

class PbcTemplateTest extends TrialBalanceTestCase
{
    public function test_ready_made_lists_add_draft_requests_once(): void
    {
        $url = $this->base().'/client-requests/template';
        $this->actingAs($this->ctx['staff'])->post($url, ['template' => 'company'])->assertForbidden();
        $this->actingAs($this->ctx['senior'])->post($url, ['template' => 'nope'])->assertSessionHasErrors('template');

        $count = count(config('pbc_templates.company.items'));
        $this->actingAs($this->ctx['senior'])->post($url, ['template' => 'company'])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($m) => str_starts_with($m, "{$count} draft request(s) added"));
        $this->assertSame($count, PbcItem::count());
        $this->assertSame(['draft'], PbcItem::pluck('status')->map->value->unique()->values()->all(), 'nothing is sent to the client');
        $this->assertSame('template', PbcItem::first()->source);
        $this->assertSame('banking', PbcItem::where('title', 'Bank statements for all accounts')->sole()->category->value);

        // Applying a second list skips the requests already on the engagement.
        $jmb = count(config('pbc_templates.jmb_mc.items'));
        $common = 12;
        $this->actingAs($this->ctx['senior'])->post($url, ['template' => 'jmb_mc'])->assertSessionHas('status', fn ($m) => str_contains($m, ($jmb - $common).' draft request(s) added, '.$common.' already on the list'));
        $this->assertSame($count + $jmb - $common, PbcItem::count());
        $this->actingAs($this->ctx['senior'])->get($this->base().'/client-requests')->assertOk()->assertSee('Add a ready-made list')->assertSee('Strata bodies (JMB / MC)');
    }
}
