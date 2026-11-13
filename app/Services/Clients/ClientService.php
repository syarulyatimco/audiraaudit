<?php

namespace App\Services\Clients;

use App\Enums\AcceptanceRisk;
use App\Enums\ApprovalType;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Support\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientService
{
    public function __construct(private ActivityLogger $logger, private ApprovalService $approvals) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): Client
    {
        return DB::transaction(function () use ($data, $actor) {
            $client = Client::create([...$data, 'created_by' => $actor->id]);
            $this->logger->log('client.created', "Client {$client->name} created", $client, [], $actor);

            return $client;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Client $client, array $data, User $actor): Client
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            $client->fill($data);
            $changed = array_keys($client->getDirty());
            $client->save();
            if ($changed) {
                $this->logger->log('client.updated', "Client {$client->name} updated", $client, ['fields' => $changed], $actor);
            }

            return $client;
        });
    }

    public function delete(Client $client, User $actor): void
    {
        if ($client->engagements()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['client' => 'Clients with engagements cannot be deleted. Mark the client inactive instead.']);
        }

        DB::transaction(function () use ($client, $actor) {
            $client->delete();
            $this->logger->log('client.deleted', "Client {$client->name} deleted", $client, [], $actor);
        });
    }

    /**
     * Record the acceptance & continuance assessment and route it to a partner for approval.
     *
     * @param  array{acceptance_risk: string, independence_confirmed: bool, acceptance_notes: string}  $data
     */
    public function submitForAcceptance(Client $client, array $data, User $actor): void
    {
        if (! in_array($client->status, [ClientStatus::Prospect, ClientStatus::Declined, ClientStatus::Inactive], true)) {
            throw ValidationException::withMessages(['client' => 'This client is not awaiting an acceptance assessment.']);
        }
        if (! $data['independence_confirmed']) {
            throw ValidationException::withMessages(['independence_confirmed' => 'Independence must be confirmed before acceptance can be requested.']);
        }

        DB::transaction(function () use ($client, $data, $actor) {
            $client->acceptance_risk = AcceptanceRisk::from($data['acceptance_risk']);
            $client->independence_confirmed = true;
            $client->acceptance_notes = $data['acceptance_notes'];
            $client->status = ClientStatus::PendingAcceptance;
            $client->save();

            $this->approvals->request($client, ApprovalType::ClientAcceptance, $actor, $data['acceptance_notes'], [
                'risk' => $client->acceptance_risk->value,
                'independence_confirmed' => true,
                'entity_type' => $client->entity_type->value,
                'framework' => $client->reporting_framework->value,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function addContact(Client $client, array $data, User $actor): ClientContact
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            if (! empty($data['is_primary'])) {
                $client->contacts()->update(['is_primary' => false]);
            }
            $contact = $client->contacts()->create($data);
            $this->logger->log('client.contact_added', "Contact {$contact->name} added to {$client->name}", $client, [], $actor);

            return $contact;
        });
    }

    public function removeContact(ClientContact $contact, User $actor): void
    {
        DB::transaction(function () use ($contact, $actor) {
            $contact->delete();
            $this->logger->log('client.contact_removed', "Contact {$contact->name} removed", $contact->client, [], $actor);
        });
    }
}
