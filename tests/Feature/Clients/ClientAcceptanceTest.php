<?php

namespace Tests\Feature\Clients;

use App\Enums\ApprovalStatus;
use App\Enums\ClientStatus;
use App\Enums\FirmRole;
use App\Models\Approval;
use App\Models\Client;
use Tests\TestCase;

class ClientAcceptanceTest extends TestCase
{
    private function clientPayload(array $overrides = []): array
    {
        return [
            'name' => 'Sinar Maju Sdn. Bhd.', 'registration_no' => '201901012345', 'entity_type' => 'private_company',
            'reporting_framework' => 'mpers', 'industry' => 'Manufacturing', 'fye_month' => 6, 'fye_day' => 30, ...$overrides,
        ];
    }

    public function test_manager_can_create_client_with_validation(): void
    {
        [$firm] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);

        $this->actingAs($manager)->post('/clients', $this->clientPayload(['fye_month' => 2, 'fye_day' => 30]))->assertSessionHasErrors('fye_day');
        $this->actingAs($manager)->post('/clients', $this->clientPayload())->assertRedirect();

        $client = Client::where('name', 'Sinar Maju Sdn. Bhd.')->firstOrFail();
        $this->assertSame(ClientStatus::Prospect, $client->status);
        $this->assertSame($firm->id, $client->firm_id);

        // duplicate registration no. within the firm
        $this->actingAs($manager)->post('/clients', $this->clientPayload(['name' => 'Dup']))->assertSessionHasErrors('registration_no');
    }

    public function test_associate_cannot_create_clients(): void
    {
        [$firm] = $this->makeFirm();
        $this->actingAs($this->staff($firm))->post('/clients', $this->clientPayload())->assertForbidden();
    }

    public function test_full_acceptance_flow_with_segregation_of_duties(): void
    {
        [$firm, $partnerA] = $this->makeFirm();
        $partnerB = $this->staff($firm, FirmRole::Partner);
        $manager = $this->staff($firm, FirmRole::Manager);
        $client = $this->client($manager, accepted: false);

        // independence must be confirmed
        $this->actingAs($manager)->post("/clients/{$client->uuid}/acceptance", [
            'acceptance_risk' => 'medium', 'acceptance_notes' => 'Management integrity assessed; no adverse findings.',
        ])->assertSessionHasErrors('independence_confirmed');

        $this->actingAs($partnerA)->post("/clients/{$client->uuid}/acceptance", [
            'acceptance_risk' => 'medium', 'independence_confirmed' => '1',
            'acceptance_notes' => 'Management integrity assessed; no adverse findings. Team has industry competence.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ClientStatus::PendingAcceptance, $client->fresh()->status);
        $approval = Approval::sole();

        // requester cannot approve their own request
        $this->actingAs($partnerA)->post("/approvals/{$approval->uuid}/decide", ['decision' => 'approve'])->assertForbidden();
        // managers are not authorised deciders
        $this->actingAs($manager)->post("/approvals/{$approval->uuid}/decide", ['decision' => 'approve'])->assertForbidden();
        // rejection needs a reason
        $this->actingAs($partnerB)->post("/approvals/{$approval->uuid}/decide", ['decision' => 'reject'])->assertSessionHasErrors('decision_comment');

        $this->actingAs($partnerB)->post("/approvals/{$approval->uuid}/decide", ['decision' => 'approve', 'decision_comment' => 'Agreed.'])
            ->assertSessionHasNoErrors();

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertSame($partnerB->id, $approval->decided_by);
        $this->assertSame(ClientStatus::Accepted, $client->fresh()->status);

        // cannot decide twice
        $this->actingAs($partnerB)->post("/approvals/{$approval->uuid}/decide", ['decision' => 'reject', 'decision_comment' => 'x'])->assertSessionHasErrors('approval');
    }

    public function test_rejection_declines_client_and_allows_resubmission(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $client = $this->client($manager, accepted: false);
        $payload = ['acceptance_risk' => 'high', 'independence_confirmed' => '1', 'acceptance_notes' => 'High risk: going concern doubts, new management.'];

        $this->actingAs($manager)->post("/clients/{$client->uuid}/acceptance", $payload);
        $this->actingAs($partner)->post('/approvals/'.Approval::sole()->uuid.'/decide', ['decision' => 'reject', 'decision_comment' => 'Insufficient information on directors.']);
        $this->assertSame(ClientStatus::Declined, $client->fresh()->status);

        $this->actingAs($manager)->post("/clients/{$client->uuid}/acceptance", $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, Approval::count());
        $this->assertSame(1, Approval::pending()->count());
    }

    public function test_database_enforces_segregation_of_duties(): void
    {
        [, $partner] = $this->makeFirm();
        $client = $this->client($partner, accepted: false);
        $approval = app(\App\Services\Approvals\ApprovalService::class)->request($client, \App\Enums\ApprovalType::ClientAcceptance, $partner);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('approvals')->where('id', $approval->id)
            ->update(['status' => 'approved', 'decided_by' => $partner->id, 'decided_at' => now()]);
    }

    public function test_client_with_engagements_cannot_be_deleted(): void
    {
        [, $partner] = $this->makeFirm();
        $client = $this->client($partner);
        $this->engagement($client, $partner);

        $this->actingAs($partner)->delete("/clients/{$client->uuid}")->assertSessionHasErrors('client');
        $this->assertNotNull($client->fresh());
    }

    public function test_contacts_can_be_added_and_removed(): void
    {
        [, $partner] = $this->makeFirm();
        $client = $this->client($partner);

        $this->actingAs($partner)->post("/clients/{$client->uuid}/contacts", ['name' => 'Encik Rahim', 'position' => 'Director', 'is_primary' => '1'])->assertSessionHasNoErrors();
        $contact = $client->contacts()->sole();
        $this->assertTrue($contact->is_primary);

        $this->actingAs($partner)->delete("/clients/{$client->uuid}/contacts/{$contact->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, $client->contacts()->count());
    }
}
