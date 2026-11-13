<?php

namespace App\Services\WorkingPapers;

use App\Enums\EngagementRole;
use App\Enums\LibraryVersionStatus;
use App\Enums\ReviewNoteStatus;
use App\Enums\WorkingPaperSection;
use App\Enums\WorkingPaperStatus;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\LibraryParagraph;
use App\Models\ReviewNote;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Models\WorkingPaperCitation;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Working-paper lifecycle: draft → prepared (preparer sign-off) → reviewed (reviewer sign-off).
 * Each sign-off records the SHA-256 of the content it attests to. Any later change to content,
 * evidence links or citations invalidates active sign-offs (kept as history) and returns the
 * paper to draft — a reviewer can never be shown as approving text they did not see.
 */
class WorkingPaperService
{
    public function __construct(private ActivityLogger $logger) {}

    /** @param array{reference: string, title: string, section: string, objective?: ?string} $data */
    public function create(Engagement $engagement, array $data, User $actor): WorkingPaper
    {
        EngagementGuard::assertEditable($engagement);

        return DB::transaction(function () use ($engagement, $data, $actor) {
            $wp = $engagement->workingPapers()->create([
                'reference' => strtoupper(trim($data['reference'])),
                'title' => $data['title'],
                'section' => $data['section'],
                'system_key' => $data['system_key'] ?? null,
                'objective' => $data['objective'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $wp->content_hash = $this->hash($wp);
            $wp->save();
            $this->logger->log('wp.created', "Working paper {$wp->reference} {$wp->title} created", $wp, [], $actor);

            return $wp;
        });
    }

    /** @param array<string, ?string> $data objective / work_performed / conclusion / title */
    public function update(WorkingPaper $wp, array $data, User $actor): WorkingPaper
    {
        EngagementGuard::assertEditable($wp->engagement);

        return DB::transaction(function () use ($wp, $data, $actor) {
            $wp->fill($data);
            if (! $wp->isDirty()) {
                return $wp;
            }
            $changed = array_keys($wp->getDirty());
            $wp->updated_by = $actor->id;
            $wp->save();
            $this->contentChanged($wp, $actor, 'Content edited ('.implode(', ', $changed).')');
            $this->logger->log('wp.updated', "Working paper {$wp->reference} updated", $wp, ['fields' => $changed], $actor);

            return $wp;
        });
    }

    /** Called after any change that affects what a sign-off attests to. */
    public function contentChanged(WorkingPaper $wp, User $actor, string $reason): void
    {
        $newHash = $this->hash($wp->fresh());
        if ($newHash === $wp->content_hash) {
            return;
        }

        $invalidated = $wp->activeSignoffs()->update(['invalidated_at' => now(), 'invalidation_reason' => $reason]);
        $wp->forceFill(['content_hash' => $newHash, 'status' => WorkingPaperStatus::Draft])->save();

        if ($invalidated > 0) {
            $this->logger->log('wp.signoffs_invalidated', "Sign-offs on {$wp->reference} invalidated: {$reason}", $wp, ['count' => $invalidated], $actor);
        }
    }

    public function signAsPreparer(WorkingPaper $wp, User $actor): void
    {
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.prepare_working_papers');

        if ($wp->status !== WorkingPaperStatus::Draft) {
            throw ValidationException::withMessages(['signoff' => 'This working paper has already been prepared.']);
        }
        if ($wp->isPlanningPaper() && ($gates = app(\App\Services\Planning\PlanningPapers::class)->gates($wp))) {
            throw ValidationException::withMessages(['signoff' => $gates]);
        }
        if (blank($wp->conclusion) && ! $wp->isEntityUnderstanding() && ! $wp->isPlanningPaper()) {
            throw ValidationException::withMessages(['conclusion' => 'Record a conclusion before signing off as preparer.']);
        }
        if ($wp->isEntityUnderstanding() && ! $wp->engagement->understandingSections()->whereNotNull('content')->where('content', '!=', '')->exists()) {
            throw ValidationException::withMessages(['signoff' => 'Document the entity understanding before signing off.']);
        }

        DB::transaction(function () use ($wp, $actor) {
            $wp->signoffs()->create(['user_id' => $actor->id, 'role' => 'preparer', 'content_hash' => $wp->content_hash, 'signed_at' => now()]);
            $wp->forceFill(['status' => WorkingPaperStatus::Prepared])->save();
            $this->logger->log('wp.prepared', "{$wp->reference} signed off as prepared", $wp, ['hash' => $wp->content_hash], $actor);
        });
    }

    public function signAsReviewer(WorkingPaper $wp, User $actor): void
    {
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.review_working_papers');

        if ($wp->status !== WorkingPaperStatus::Prepared) {
            throw ValidationException::withMessages(['signoff' => 'Only prepared working papers can be reviewed.']);
        }
        if ($wp->activeSignoffs()->where('role', 'preparer')->where('user_id', $actor->id)->exists()) {
            throw new AuthorizationException('The preparer cannot review their own working paper.');
        }
        if ($wp->openNotesCount() > 0) {
            throw ValidationException::withMessages(['signoff' => 'Clear all review notes before signing off the review.']);
        }

        DB::transaction(function () use ($wp, $actor) {
            $wp->signoffs()->create(['user_id' => $actor->id, 'role' => 'reviewer', 'content_hash' => $wp->content_hash, 'signed_at' => now()]);
            $wp->forceFill(['status' => WorkingPaperStatus::Reviewed])->save();
            $this->logger->log('wp.reviewed', "{$wp->reference} signed off as reviewed", $wp, ['hash' => $wp->content_hash], $actor);
        });
    }

    // --- Review notes -----------------------------------------------------------------------

    public function raiseNote(WorkingPaper $wp, string $body, User $actor): ReviewNote
    {
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.raise_review_notes');

        return DB::transaction(function () use ($wp, $body, $actor) {
            $note = $wp->reviewNotes()->create(['raised_by' => $actor->id, 'body' => $body]);
            // A note on a reviewed paper withdraws the review sign-off.
            if ($wp->status === WorkingPaperStatus::Reviewed) {
                $wp->activeSignoffs()->where('role', 'reviewer')->update(['invalidated_at' => now(), 'invalidation_reason' => 'Review note raised after review']);
                $wp->forceFill(['status' => WorkingPaperStatus::Prepared])->save();
            }
            $this->logger->log('wp.note_raised', "Review note raised on {$wp->reference}", $wp, ['note' => $note->uuid], $actor);

            return $note;
        });
    }

    public function respondToNote(ReviewNote $note, string $response, User $actor): void
    {
        $wp = $note->workingPaper;
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.edit_working_papers');
        if ($note->status === ReviewNoteStatus::Cleared) {
            throw ValidationException::withMessages(['response' => 'This note has already been cleared.']);
        }

        DB::transaction(function () use ($note, $response, $actor, $wp) {
            $note->forceFill(['response' => $response, 'responded_by' => $actor->id, 'responded_at' => now(), 'status' => ReviewNoteStatus::Responded])->save();
            $this->logger->log('wp.note_responded', "Response to review note on {$wp->reference}", $wp, ['note' => $note->uuid], $actor);
        });
    }

    /** Only the person who raised the note, or the engagement partner, may clear it. */
    public function clearNote(ReviewNote $note, User $actor): void
    {
        $wp = $note->workingPaper;
        EngagementGuard::assertEditable($wp->engagement);
        $isPartner = $wp->engagement->roleOf($actor) === EngagementRole::EngagementPartner;
        if ($note->raised_by !== $actor->id && ! $isPartner) {
            throw new AuthorizationException('Only the reviewer who raised the note or the engagement partner can clear it.');
        }
        if ($note->status !== ReviewNoteStatus::Responded) {
            throw ValidationException::withMessages(['note' => 'A note can be cleared only after the preparer has responded.']);
        }

        DB::transaction(function () use ($note, $actor, $wp) {
            $note->forceFill(['status' => ReviewNoteStatus::Cleared, 'cleared_by' => $actor->id, 'cleared_at' => now()])->save();
            $this->logger->log('wp.note_cleared', "Review note on {$wp->reference} cleared", $wp, ['note' => $note->uuid], $actor);
        });
    }

    // --- Evidence & citations ---------------------------------------------------------------

    public function linkDocument(WorkingPaper $wp, Document $document, User $actor): void
    {
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.edit_working_papers');
        if ($document->engagement_id !== $wp->engagement_id || $document->archived_at) {
            throw ValidationException::withMessages(['document_id' => 'Only active documents from this engagement can be linked.']);
        }
        if ($wp->documents()->whereKey($document->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($wp, $document, $actor) {
            $wp->documents()->attach($document->id, ['firm_id' => $wp->firm_id, 'linked_by' => $actor->id]);
            $this->contentChanged($wp, $actor, "Evidence linked: {$document->title}");
            $this->logger->log('wp.document_linked', "{$document->title} linked to {$wp->reference}", $wp, ['document' => $document->uuid], $actor);
        });
    }

    public function unlinkDocument(WorkingPaper $wp, Document $document, User $actor): void
    {
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.edit_working_papers');

        DB::transaction(function () use ($wp, $document, $actor) {
            $wp->documents()->detach($document->id);
            $this->contentChanged($wp, $actor, "Evidence unlinked: {$document->title}");
            $this->logger->log('wp.document_unlinked', "{$document->title} unlinked from {$wp->reference}", $wp, ['document' => $document->uuid], $actor);
        });
    }

    public function addCitation(WorkingPaper $wp, LibraryParagraph $paragraph, ?string $note, User $actor): void
    {
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.edit_working_papers');
        if ($paragraph->version->status !== LibraryVersionStatus::Published) {
            throw ValidationException::withMessages(['library_paragraph_id' => 'Only paragraphs from the current published version can be cited.']);
        }

        DB::transaction(function () use ($wp, $paragraph, $note, $actor) {
            $wp->citations()->firstOrCreate(['library_paragraph_id' => $paragraph->id], ['note' => $note, 'added_by' => $actor->id]);
            $this->contentChanged($wp, $actor, 'Citation added: '.$paragraph->citation());
            $this->logger->log('wp.citation_added', 'Cited '.$paragraph->citation()." on {$wp->reference}", $wp, ['paragraph_id' => $paragraph->id], $actor);
        });
    }

    public function removeCitation(WorkingPaperCitation $citation, User $actor): void
    {
        $wp = $citation->workingPaper;
        EngagementGuard::assertEditable($wp->engagement);
        $this->assertEngagementPermission($actor, $wp, 'engagement.edit_working_papers');

        DB::transaction(function () use ($citation, $wp, $actor) {
            $label = $citation->paragraph->citation();
            $citation->delete();
            $this->contentChanged($wp, $actor, 'Citation removed: '.$label);
            $this->logger->log('wp.citation_removed', "Removed citation {$label} from {$wp->reference}", $wp, [], $actor);
        });
    }

    // --- Helpers -----------------------------------------------------------------------------

    /** The engagement's entity-understanding paper (B1), created on first use. */
    public function entityUnderstandingPaper(Engagement $engagement, User $actor): WorkingPaper
    {
        $existing = $engagement->workingPapers()->where('system_key', WorkingPaper::ENTITY_UNDERSTANDING)->first();
        if ($existing) {
            return $existing;
        }
        $reference = $engagement->workingPapers()->where('reference', 'B1')->exists() ? 'B1.0' : 'B1';

        return $this->create($engagement, [
            'reference' => $reference, 'title' => 'Understanding the entity and its environment',
            'section' => WorkingPaperSection::EntityUnderstanding->value, 'system_key' => WorkingPaper::ENTITY_UNDERSTANDING,
            'objective' => 'Obtain an understanding of the entity and its environment, the applicable financial reporting framework and the system of internal control, sufficient to identify and assess risks of material misstatement.',
        ], $actor);
    }

    public function suggestReference(Engagement $engagement, WorkingPaperSection $section): string
    {
        $prefix = $section->prefix();
        $n = 1;
        $existing = $engagement->workingPapers()->where('reference', 'like', $prefix.'%')->pluck('reference')->all();
        while (in_array($prefix.$n, $existing, true)) {
            $n++;
        }

        return $prefix.$n;
    }

    /** SHA-256 over everything a sign-off attests to. */
    public function hash(WorkingPaper $wp): string
    {
        $parts = [
            'title' => $wp->title,
            'objective' => (string) $wp->objective,
            'work' => (string) $wp->work_performed,
            'conclusion' => (string) $wp->conclusion,
            'documents' => $wp->documents()->orderBy('documents.id')->pluck('documents.current_version_id', 'documents.id')->all(),
            'citations' => $wp->citations()->orderBy('library_paragraph_id')->pluck('library_paragraph_id')->all(),
        ];
        // Lead-schedule papers also attest to the FSLI's figures (TB, mapping, posted adjustments).
        $fsli = \App\Models\EngagementFsli::where('working_paper_id', $wp->id)->first();
        if ($fsli) {
            $parts['lead'] = app(\App\Services\TrialBalance\LeadScheduleService::class)->fingerprint($fsli);
        }
        if ($wp->isPlanningPaper()) {
            $parts += app(\App\Services\Planning\PlanningPapers::class)->hashParts($wp);
        }
        if ($wp->isEntityUnderstanding()) {
            $parts['sections'] = $wp->engagement->understandingSections()->orderBy('section_key')->pluck('content', 'section_key')->all();
        }

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    private function assertEngagementPermission(User $actor, WorkingPaper $wp, string $permission): void
    {
        if (! Permissions::allowsOnEngagement($actor, $wp->engagement, $permission)) {
            throw new AuthorizationException('Your role on this engagement does not allow this action.');
        }
    }
}
