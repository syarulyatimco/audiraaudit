<?php

namespace App\Services\Portal;

use App\Enums\DocumentCategory;
use App\Enums\PbcCategory;
use App\Enums\PbcStatus;
use App\Models\Engagement;
use App\Models\PbcItem;
use App\Models\User;
use App\Notifications\ClientPortalUpdate;
use App\Services\Documents\DocumentService;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * PBC lifecycle: draft → requested (client notified) → submitted (client uploads) → accepted, or
 * returned to the client with a reason. Client uploads become scanned, hashed engagement documents.
 */
class PbcService
{
    public function __construct(private DocumentService $documents, private ActivityLogger $logger) {}

    public function create(Engagement $engagement, array $data, User $actor, string $source = 'manual'): PbcItem
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement);
        $clean = $this->validate($engagement, $data);

        return DB::transaction(function () use ($engagement, $clean, $actor, $source) {
            Engagement::whereKey($engagement->id)->lockForUpdate()->first();
            $n = $engagement->pbcItems()->count() + 1;
            $item = $engagement->pbcItems()->create($clean + ['reference' => sprintf('PBC-%03d', $n), 'requested_by' => $actor->id, 'source' => $source]);
            $this->logger->log('pbc.created', "PBC item {$item->reference} {$item->title} created", $item, [], $actor);

            return $item;
        });
    }

    public function update(PbcItem $item, array $data, User $actor): PbcItem
    {
        EngagementGuard::assertEditable($item->engagement);
        $this->authorise($actor, $item->engagement);
        if (in_array($item->status, [PbcStatus::Accepted, PbcStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['item' => 'Accepted or cancelled requests cannot be changed.']);
        }
        $item->fill($this->validate($item->engagement, $data))->save();

        return $item;
    }

    /** Send draft (or returned) items to the client and notify the assigned contacts once. */
    public function request(Engagement $engagement, array $itemIds, User $actor): int
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement);
        $items = $engagement->pbcItems()->whereIn('id', $itemIds)->where('status', PbcStatus::Draft->value)->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Choose draft requests to send.']);
        }
        $contacts = $engagement->client->portalUsers()->where('status', 'active')->get();
        if ($contacts->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'The client has no active portal users. Invite a client contact first.']);
        }
        DB::transaction(function () use ($items, $actor) {
            foreach ($items as $item) {
                $item->forceFill(['status' => PbcStatus::Requested, 'requested_at' => now()])->save();
            }
            $this->logger->log('pbc.requested', $items->count().' PBC item(s) requested from the client', $items->first()->engagement, ['items' => $items->pluck('reference')->all()], $actor);
        });
        $recipients = $items->pluck('assigned_contact_id')->filter()->unique()->isNotEmpty()
            ? $contacts->whereIn('id', $items->pluck('assigned_contact_id')->filter()->unique()->all()) : $contacts;
        Notification::send($recipients, new ClientPortalUpdate($actor->firm->name, 'Documents requested for your audit',
            $items->count().' item(s) have been requested for the audit of '.$engagement->client->name.'.', route('portal.engagement', $engagement)));

        return $items->count();
    }

    /** A client portal user uploads a file against a requested item. */
    public function clientUpload(PbcItem $item, UploadedFile $file, ?string $note, User $client): void
    {
        $this->assertClientAccess($item, $client);
        if (! $item->isOpen() && $item->status !== PbcStatus::Submitted) {
            throw ValidationException::withMessages(['file' => 'This request is not open for uploads.']);
        }
        EngagementGuard::assertEditable($item->engagement);
        $document = $this->documents->upload($item->engagement, $file, mb_substr($item->reference.' · '.$item->title, 0, 200),
            DocumentCategory::ClientProvided, $note ? mb_substr("Client note: {$note}", 0, 2000) : null, $client);
        DB::transaction(function () use ($item, $document, $client) {
            $item->documents()->syncWithoutDetaching([$document->id => ['firm_id' => $item->firm_id, 'linked_by' => $client->id]]);
            $item->forceFill(['status' => PbcStatus::Submitted, 'submitted_at' => now()])->save();
            $this->logger->log('pbc.submitted', "Client uploaded {$document->title} for {$item->reference}", $item, ['document' => $document->uuid], $client);
        });
    }

    public function accept(PbcItem $item, User $actor): void
    {
        $this->review($item, $actor);
        $item->forceFill(['status' => PbcStatus::Accepted, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => null])->save();
        $this->logger->log('pbc.accepted', "PBC item {$item->reference} accepted", $item, [], $actor);
    }

    public function returnToClient(PbcItem $item, string $reason, User $actor): void
    {
        $this->review($item, $actor);
        $item->forceFill(['status' => PbcStatus::Returned, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => trim($reason)])->save();
        $this->logger->log('pbc.returned', "PBC item {$item->reference} returned to the client", $item, ['reason' => $reason], $actor);
        Notification::send($item->engagement->client->portalUsers()->where('status', 'active')->get(),
            new ClientPortalUpdate($actor->firm->name, 'A document request needs your attention', "Request {$item->reference} has been returned with a note from your audit team.", route('portal.engagement', $item->engagement)));
    }

    public function cancel(PbcItem $item, User $actor): void
    {
        EngagementGuard::assertEditable($item->engagement);
        $this->authorise($actor, $item->engagement);
        if ($item->status === PbcStatus::Accepted) {
            throw ValidationException::withMessages(['item' => 'Accepted items cannot be cancelled.']);
        }
        $item->forceFill(['status' => PbcStatus::Cancelled])->save();
        $this->logger->log('pbc.cancelled', "PBC item {$item->reference} cancelled", $item, [], $actor);
    }

    public function assertClientAccess(PbcItem $item, User $client): void
    {
        if (! $client->isClientUser() || $item->engagement->client_id !== $client->client_id
            || ! in_array($item->status, [PbcStatus::Requested, PbcStatus::Submitted, PbcStatus::Accepted, PbcStatus::Returned], true)) {
            abort(404);
        }
    }

    private function review(PbcItem $item, User $actor): void
    {
        EngagementGuard::assertEditable($item->engagement);
        $this->authorise($actor, $item->engagement);
        if ($item->status !== PbcStatus::Submitted) {
            throw ValidationException::withMessages(['item' => 'Only submitted items can be reviewed.']);
        }
    }

    private function validate(Engagement $engagement, array $data): array
    {
        if (blank($data['title'] ?? null)) {
            throw ValidationException::withMessages(['title' => 'A title is required.']);
        }
        $contact = $data['assigned_contact_id'] ?? null;
        if ($contact && ! $engagement->client->portalUsers()->whereKey($contact)->exists()) {
            throw ValidationException::withMessages(['assigned_contact_id' => 'Choose a portal user of this client.']);
        }
        $procedure = $data['procedure_id'] ?? null;
        if ($procedure && ! $engagement->procedures()->whereKey($procedure)->exists()) {
            throw ValidationException::withMessages(['procedure_id' => 'Unknown procedure.']);
        }

        return [
            'title' => trim($data['title']), 'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'category' => PbcCategory::tryFrom((string) ($data['category'] ?? 'other')) ?? PbcCategory::Other,
            'procedure_id' => $procedure ?: null, 'assigned_contact_id' => $contact ?: null,
            'due_on' => filled($data['due_on'] ?? null) ? $data['due_on'] : null,
        ];
    }

    private function authorise(User $actor, Engagement $engagement): void
    {
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.manage_pbc')) {
            throw new AuthorizationException('Your role on this engagement does not allow managing client requests.');
        }
    }
}
