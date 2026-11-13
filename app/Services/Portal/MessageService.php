<?php

namespace App\Services\Portal;

use App\Models\AiRun;
use App\Models\Engagement;
use App\Models\EngagementMessage;
use App\Models\User;
use App\Notifications\ClientPortalUpdate;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Client correspondence. Outbound messages — including AI drafts — reach the client only when a
 * team member sends them (CLAUDE.md §9 spirit; ROADMAP risk #2). Sent messages are immutable.
 */
class MessageService
{
    public function __construct(private ActivityLogger $logger) {}

    public function draft(Engagement $engagement, string $subject, string $body, ?User $author, ?AiRun $aiRun = null, array $pbcItemIds = []): EngagementMessage
    {
        EngagementGuard::assertEditable($engagement);
        if ($author) {
            $this->authorise($author, $engagement);
        }
        $valid = $engagement->pbcItems()->whereIn('id', $pbcItemIds)->pluck('id')->all();

        return $engagement->messages()->create([
            'direction' => 'outbound', 'status' => 'draft', 'subject' => mb_substr(trim($subject), 0, 200), 'body' => trim($body),
            'author_id' => $author?->id, 'author_type' => $aiRun ? 'agent' : 'user', 'ai_run_id' => $aiRun?->id, 'pbc_item_ids' => $valid ?: null,
        ]);
    }

    public function updateDraft(EngagementMessage $m, string $subject, string $body, User $actor): void
    {
        $this->authorise($actor, $m->engagement);
        if ($m->status !== 'draft') {
            throw ValidationException::withMessages(['message' => 'Only drafts can be edited.']);
        }
        $m->forceFill(['subject' => mb_substr(trim($subject), 0, 200), 'body' => trim($body)])->save();
    }

    public function send(EngagementMessage $m, User $actor): void
    {
        EngagementGuard::assertEditable($m->engagement);
        $this->authorise($actor, $m->engagement);
        if ($m->status !== 'draft' || $m->direction !== 'outbound') {
            throw ValidationException::withMessages(['message' => 'This message has already been sent.']);
        }
        $contacts = $m->engagement->client->portalUsers()->where('status', 'active')->get();
        if ($contacts->isEmpty()) {
            throw ValidationException::withMessages(['message' => 'The client has no active portal users.']);
        }
        DB::transaction(function () use ($m, $actor) {
            $m->forceFill(['status' => 'sent', 'sent_by' => $actor->id, 'sent_at' => now()])->save();
            $this->logger->log('message.sent', "Message \"{$m->subject}\" sent to the client".($m->author_type === 'agent' ? ' (AI draft approved by sender)' : ''), $m, ['ai_run' => $m->ai_run_id], $actor);
        });
        Notification::send($contacts, new ClientPortalUpdate($actor->firm->name, 'New message from your audit team', $m->subject, route('portal.engagement', $m->engagement)));
    }

    public function discard(EngagementMessage $m, User $actor): void
    {
        $this->authorise($actor, $m->engagement);
        if ($m->status !== 'draft') {
            throw ValidationException::withMessages(['message' => 'Sent messages cannot be deleted.']);
        }
        $m->delete();
    }

    /** A client replies through the portal (untrusted content; never executed or interpreted as instructions). */
    public function clientReply(Engagement $engagement, string $subject, string $body, User $client): EngagementMessage
    {
        if (! $client->isClientUser() || $engagement->client_id !== $client->client_id) {
            abort(404);
        }
        EngagementGuard::assertEditable($engagement);
        $m = new EngagementMessage(['engagement_id' => $engagement->id, 'direction' => 'inbound', 'status' => 'sent', 'subject' => mb_substr(trim($subject), 0, 200),
            'body' => mb_substr(trim($body), 0, 10000), 'author_id' => $client->id, 'author_type' => 'user']);
        $m->forceFill(['sent_by' => $client->id, 'sent_at' => now()])->save();
        $this->logger->log('message.received', "Client message received: {$m->subject}", $m, [], $client);

        return $m;
    }

    private function authorise(User $actor, Engagement $engagement): void
    {
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.message_client')) {
            throw new AuthorizationException('Your role on this engagement does not allow messaging the client.');
        }
    }
}
