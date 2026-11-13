<?php

namespace Tests\Feature\Ai;

use App\Enums\FirmRole;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    public function test_firm_admin_opts_in_with_acknowledgement(): void
    {
        [$firm, $admin] = $this->makeFirm();
        $this->actingAs($admin)->get('/firm/ai')->assertOk()->assertSee('No AI provider is configured');

        $this->actingAs($admin)->put('/firm/ai', ['ai_enabled' => '1', 'enabled_agents' => ['accounting_tb']])->assertSessionHasErrors('acknowledge');
        $this->assertFalse($firm->fresh()->ai_enabled);

        $this->actingAs($admin)->put('/firm/ai', ['ai_enabled' => '1', 'acknowledge' => '1', 'enabled_agents' => ['accounting_tb']])->assertSessionHasNoErrors();
        $firm->refresh();
        $this->assertTrue($firm->ai_enabled);
        $this->assertSame($admin->id, $firm->ai_enabled_by);

        // later saves need no re-acknowledgement; unchecking an agent disables it
        $this->actingAs($admin)->put('/firm/ai', ['ai_enabled' => '1', 'enabled_agents' => []])->assertSessionHasNoErrors();
        $this->assertSame(array_keys(config('ai.agents')), $firm->fresh()->ai_disabled_agents);

        $this->actingAs($admin)->put('/firm/ai', ['ai_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($firm->fresh()->ai_enabled);
    }

    public function test_only_firm_admins_manage_ai(): void
    {
        [$firm] = $this->makeFirm();
        $partner = $this->staff($firm, FirmRole::Partner);
        $this->actingAs($partner)->get('/firm/ai')->assertForbidden();
        $this->actingAs($partner)->put('/firm/ai', ['ai_enabled' => '1', 'acknowledge' => '1'])->assertForbidden();
    }
}
