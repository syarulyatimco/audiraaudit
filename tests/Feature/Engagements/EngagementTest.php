<?php

namespace Tests\Feature\Engagements;

use App\Enums\EngagementRole;
use App\Enums\EngagementStatus;
use App\Enums\FirmRole;
use App\Models\Engagement;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    private function payload(int $clientId, int $partnerId, array $extra = []): array
    {
        return [
            'client_id' => $clientId, 'name' => 'Statutory audit FYE 2025', 'engagement_type' => 'statutory_audit',
            'reporting_framework' => 'mpers', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'reporting_deadline' => '2026-06-30', 'engagement_partner_id' => $partnerId, ...$extra,
        ];
    }

    public function test_engagement_requires_accepted_client(): void
    {
        [, $partner] = $this->makeFirm();
        $prospect = $this->client($partner, accepted: false);

        $this->actingAs($partner)->post('/engagements', $this->payload($prospect->id, $partner->id))->assertSessionHasErrors('client_id');
        $this->assertSame(0, Engagement::count());
    }

    public function test_manager_creates_engagement_with_team_and_generated_code(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $client = $this->client($partner);

        $this->actingAs($manager)->post('/engagements', $this->payload($client->id, $partner->id))->assertRedirect();

        $engagement = Engagement::with('members')->sole();
        $this->assertSame('ENG-2025-0001', $engagement->code);
        $this->assertSame(EngagementStatus::Planning, $engagement->status);
        $this->assertSame(EngagementRole::EngagementPartner, $engagement->roleOf($partner));
        $this->assertSame(EngagementRole::Manager, $engagement->roleOf($manager));
    }

    public function test_engagement_partner_must_be_a_partner_and_period_valid(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $client = $this->client($partner);

        $this->actingAs($partner)->post('/engagements', $this->payload($client->id, $manager->id))->assertSessionHasErrors('role');
        $this->actingAs($partner)->post('/engagements', $this->payload($client->id, $partner->id, ['period_end' => '2024-12-31']))->assertSessionHasErrors('period_end');
    }

    public function test_staff_see_only_assigned_engagements_while_partners_see_all(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $associate = $this->staff($firm);
        $client = $this->client($partner, attrs: ['name' => 'Client One Sdn. Bhd.']);
        $client2 = $this->client($partner, attrs: ['name' => 'Client Two Sdn. Bhd.']);
        $assigned = $this->engagement($client, $partner, [[$associate, EngagementRole::Staff]]);
        $other = $this->engagement($client2, $partner);

        $this->actingAs($associate)->get('/engagements')->assertOk()->assertSee('Client One')->assertDontSee('Client Two');
        $this->actingAs($associate)->get("/engagements/{$assigned->uuid}")->assertOk();
        $this->actingAs($associate)->get("/engagements/{$other->uuid}")->assertForbidden();

        $otherPartner = $this->staff($firm, FirmRole::Partner);
        $this->actingAs($otherPartner)->get("/engagements/{$other->uuid}")->assertOk();
    }

    public function test_team_management_rules(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $senior = $this->staff($firm, FirmRole::Senior);
        $associate = $this->staff($firm);
        $eqr = $this->staff($firm, FirmRole::Partner);
        $admin = $this->staff($firm, FirmRole::Administrator);
        $engagement = $this->engagement($this->client($partner), $partner, [[$senior, EngagementRole::Senior]]);

        // seniors cannot manage the team
        $this->actingAs($senior)->post("/engagements/{$engagement->uuid}/members", ['user_id' => $associate->id, 'role' => 'staff'])->assertForbidden();

        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/members", ['user_id' => $associate->id, 'role' => 'staff'])->assertSessionHasNoErrors();
        // EQR must be a partner
        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/members", ['user_id' => $senior->id, 'role' => 'eqr_reviewer'])->assertSessionHasErrors();
        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/members", ['user_id' => $eqr->id, 'role' => 'eqr_reviewer'])->assertSessionHasNoErrors();
        // non-audit administrators cannot join engagement teams
        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/members", ['user_id' => $admin->id, 'role' => 'staff'])->assertSessionHasErrors('role');
        // engagement partner role cannot be added via members endpoint
        $this->actingAs($partner)->post("/engagements/{$engagement->uuid}/members", ['user_id' => $admin->id, 'role' => 'engagement_partner'])->assertSessionHasErrors('role');

        $member = $engagement->members()->where('user_id', $associate->id)->sole();
        $this->actingAs($partner)->delete("/engagements/{$engagement->uuid}/members/{$member->id}")->assertSessionHasNoErrors();

        $partnerMember = $engagement->members()->where('role', 'engagement_partner')->sole();
        $this->actingAs($partner)->delete("/engagements/{$engagement->uuid}/members/{$partnerMember->id}")->assertSessionHasErrors('member');
    }

    public function test_status_transitions_and_sign_off_gate(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $associate = $this->staff($firm);
        $engagement = $this->engagement($this->client($partner), $partner, [[$associate, EngagementRole::Staff]]);

        $this->actingAs($associate)->put("/engagements/{$engagement->uuid}/status", ['status' => 'fieldwork'])->assertForbidden();
        $this->actingAs($partner)->put("/engagements/{$engagement->uuid}/status", ['status' => 'fieldwork'])->assertSessionHasNoErrors();
        $this->assertSame(EngagementStatus::Fieldwork, $engagement->fresh()->status);

        $this->actingAs($partner)->put("/engagements/{$engagement->uuid}/status", ['status' => 'signed_off'])->assertSessionHasErrors('status');
        $this->assertSame(EngagementStatus::Fieldwork, $engagement->fresh()->status);
    }

    public function test_partner_reassignment_requires_firm_wide_role(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $newPartner = $this->staff($firm, FirmRole::Partner);
        $engagement = $this->engagement($this->client($partner), $partner, [[$manager, EngagementRole::Manager]]);

        $this->actingAs($manager)->put("/engagements/{$engagement->uuid}/partner", ['user_id' => $newPartner->id])->assertForbidden();
        $this->actingAs($partner)->put("/engagements/{$engagement->uuid}/partner", ['user_id' => $newPartner->id])->assertSessionHasNoErrors();
        $this->assertSame(EngagementRole::EngagementPartner, $engagement->fresh()->roleOf($newPartner));
        $this->assertNull($engagement->fresh()->roleOf($partner));
    }

    public function test_engagement_pages_render(): void
    {
        [, $partner] = $this->makeFirm();
        $client = $this->client($partner);
        $engagement = $this->engagement($client, $partner);

        $this->actingAs($partner)->get('/')->assertOk()->assertSee($client->name);
        $this->actingAs($partner)->get('/engagements/create')->assertOk()->assertSee($client->name);
        $this->actingAs($partner)->get("/engagements/{$engagement->uuid}")->assertOk()->assertSee('Engagement team');
        $this->actingAs($partner)->get("/engagements/{$engagement->uuid}/edit")->assertOk();
        $this->actingAs($partner)->put("/engagements/{$engagement->uuid}", [
            'name' => 'Renamed audit', 'engagement_type' => 'statutory_audit', 'reporting_framework' => 'mfrs',
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'code' => $engagement->code,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Renamed audit', $engagement->fresh()->name);
    }
}
