<?php

namespace Tests\Feature\Tenancy;

use App\Models\Client;
use App\Models\Engagement;
use App\Support\Tenancy\TenantViolation;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_users_only_see_their_own_firms_clients(): void
    {
        [, $adminA] = $this->makeFirm();
        [, $adminB] = $this->makeFirm();
        $this->client($adminA, attrs: ['name' => 'Alpha Trading Sdn. Bhd.']);
        $this->client($adminB, attrs: ['name' => 'Bravo Holdings Bhd.']);

        $this->actingAs($adminA)->get('/clients')
            ->assertOk()
            ->assertSee('Alpha Trading')
            ->assertDontSee('Bravo Holdings');
    }

    public function test_foreign_client_and_engagement_urls_return_404(): void
    {
        [, $adminA] = $this->makeFirm();
        [, $adminB] = $this->makeFirm();
        $foreignClient = $this->client($adminB);
        $foreignEngagement = $this->engagement($foreignClient, $adminB);

        $this->actingAs($adminA)->get("/clients/{$foreignClient->uuid}")->assertNotFound();
        $this->actingAs($adminA)->get("/engagements/{$foreignEngagement->uuid}")->assertNotFound();
        $this->actingAs($adminA)->put("/clients/{$foreignClient->uuid}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertNotSame('Hijacked', $foreignClient->fresh()->name);
    }

    public function test_cannot_create_engagement_against_foreign_client_id(): void
    {
        [, $adminA] = $this->makeFirm();
        [, $adminB] = $this->makeFirm();
        $foreignClient = $this->client($adminB);

        $this->actingAs($adminA)->post('/engagements', [
            'client_id' => $foreignClient->id, 'name' => 'X', 'engagement_type' => 'statutory_audit',
            'reporting_framework' => 'mpers', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'engagement_partner_id' => $adminA->id,
        ])->assertNotFound();

        $this->assertSame(0, Engagement::where('client_id', $foreignClient->id)->count());
    }

    public function test_scope_fails_closed_without_tenant(): void
    {
        [, $admin] = $this->makeFirm();
        $this->client($admin);

        $this->tenant()->set(null); // not system, no tenant
        $this->assertSame(0, Client::count());
    }

    public function test_cross_tenant_write_is_blocked_in_application_layer(): void
    {
        [$firmA] = $this->makeFirm();
        [, $adminB] = $this->makeFirm();

        $this->tenant()->set($firmA->id);
        $this->expectException(TenantViolation::class);
        $client = Client::factory()->make(['created_by' => $adminB->id]);
        $client->firm_id = $adminB->firm_id;
        $client->save();
    }

    public function test_firm_id_is_immutable(): void
    {
        [, $adminA] = $this->makeFirm();
        [$firmB] = $this->makeFirm();
        $client = $this->client($adminA);

        $this->expectException(TenantViolation::class);
        $client->firm_id = $firmB->id;
        $client->save();
    }
}
