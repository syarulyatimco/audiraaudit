<?php

namespace App\Services\WorkingPapers;

use App\Models\Engagement;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class EntityUnderstandingService
{
    public function __construct(private WorkingPaperService $papers, private ActivityLogger $logger) {}

    /** @param array<string, ?string> $sections section_key => content (unknown keys ignored) */
    public function save(Engagement $engagement, array $sections, User $actor): void
    {
        EngagementGuard::assertEditable($engagement);
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.edit_working_papers')) {
            throw new AuthorizationException('Your role on this engagement does not allow editing.');
        }

        $allowed = array_keys(config('audira.entity_understanding'));

        DB::transaction(function () use ($engagement, $sections, $actor, $allowed) {
            $wp = $this->papers->entityUnderstandingPaper($engagement, $actor);
            $changed = [];
            foreach ($sections as $key => $content) {
                if (! in_array($key, $allowed, true)) {
                    continue;
                }
                $content = $content === null ? null : trim($content);
                $row = $engagement->understandingSections()->firstOrNew(['section_key' => $key]);
                if ($row->exists && $row->content === $content) {
                    continue;
                }
                if (! $row->exists && blank($content)) {
                    continue;
                }
                $row->content = $content;
                $row->updated_by = $actor->id;
                $row->save();
                $changed[] = $key;
            }

            if ($changed) {
                $this->papers->contentChanged($wp, $actor, 'Entity understanding edited');
                $this->logger->log('entity_understanding.updated', 'Entity understanding updated ('.count($changed).' sections)', $engagement, ['sections' => $changed], $actor);
            }
        });
    }
}
