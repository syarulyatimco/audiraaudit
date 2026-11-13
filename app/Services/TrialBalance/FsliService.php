<?php

namespace App\Services\TrialBalance;

use App\Enums\FsliClassification;
use App\Enums\WorkingPaperSection;
use App\Models\Engagement;
use App\Models\EngagementFsli;
use App\Models\FsliTemplate;
use App\Models\User;
use App\Services\WorkingPapers\WorkingPaperService;
use App\Support\Audit\ActivityLogger;
use App\Support\EngagementGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FsliService
{
    public function __construct(private ActivityLogger $logger, private WorkingPaperService $papers) {}

    /** Copy the default template the first time an engagement needs FSLIs. */
    public function ensureInitialised(Engagement $engagement, User $actor): void
    {
        if ($engagement->fslis()->exists()) {
            return;
        }
        // Strata bodies get the JMB/MC template; everyone else the default corporate one.
        $code = $engagement->client?->entity_type === \App\Enums\EntityType::JmbMc ? 'strata' : null;
        $template = ($code ? FsliTemplate::where('code', $code)->with('items')->first() : null)
            ?? FsliTemplate::where('is_default', true)->with('items')->first();
        if (! $template) {
            return;
        }
        foreach ($template->items as $item) {
            $engagement->fslis()->create([
                'code' => $item->code, 'name' => $item->name, 'classification' => $item->classification, 'sort_order' => $item->sort_order,
            ]);
        }
        $this->logger->log('fsli.initialised', "FSLIs created from template \"{$template->name}\"", $engagement, [], $actor);
    }

    /** @param array{code: string, name: string, classification: string} $data */
    public function add(Engagement $engagement, array $data, User $actor): EngagementFsli
    {
        EngagementGuard::assertEditable($engagement);
        $code = strtoupper(trim($data['code']));
        if ($engagement->fslis()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'This FSLI code already exists in the engagement.']);
        }
        $class = FsliClassification::from($data['classification']);
        $sort = (int) $engagement->fslis()->where('classification', $class->value)->max('sort_order') + 1;

        return DB::transaction(function () use ($engagement, $code, $data, $class, $sort, $actor) {
            $fsli = $engagement->fslis()->create(['code' => $code, 'name' => $data['name'], 'classification' => $class, 'sort_order' => $sort ?: 999, 'is_custom' => true]);
            $this->logger->log('fsli.added', "FSLI {$code} {$fsli->name} added", $engagement, [], $actor);

            return $fsli;
        });
    }

    public function rename(EngagementFsli $fsli, string $name, User $actor): void
    {
        EngagementGuard::assertEditable($fsli->engagement);
        DB::transaction(function () use ($fsli, $name, $actor) {
            $old = $fsli->name;
            $fsli->update(['name' => $name]);
            $this->logger->log('fsli.renamed', "FSLI {$fsli->code} renamed from \"{$old}\"", $fsli->engagement, [], $actor);
        });
    }

    public function delete(EngagementFsli $fsli, User $actor): void
    {
        EngagementGuard::assertEditable($fsli->engagement);
        if ($fsli->mappings()->exists()) {
            throw ValidationException::withMessages(['fsli' => 'Remap the accounts in this FSLI before deleting it.']);
        }
        DB::transaction(function () use ($fsli, $actor) {
            $fsli->delete();
            $this->logger->log('fsli.deleted', "FSLI {$fsli->code} deleted", $fsli->engagement, [], $actor);
        });
    }

    /** Create the lead-schedule working paper for an FSLI (its sign-off covers the figures). */
    public function createLeadPaper(EngagementFsli $fsli, User $actor): \App\Models\WorkingPaper
    {
        if ($fsli->workingPaper) {
            return $fsli->workingPaper;
        }

        return DB::transaction(function () use ($fsli, $actor) {
            $engagement = $fsli->engagement;
            $ref = 'L-'.$fsli->code;
            $n = 1;
            while ($engagement->workingPapers()->where('reference', $ref)->exists()) {
                $ref = 'L-'.$fsli->code.'-'.(++$n);
            }
            $wp = $this->papers->create($engagement, [
                'reference' => mb_substr($ref, 0, 20), 'title' => 'Lead schedule — '.$fsli->name,
                'section' => WorkingPaperSection::Fieldwork->value,
                'objective' => 'Agree the '.$fsli->name.' balance to the trial balance and document the audit work on this line item.',
            ], $actor);
            $fsli->update(['working_paper_id' => $wp->id]);
            $this->papers->contentChanged($wp->fresh(), $actor, 'Lead schedule figures attached');

            return $wp;
        });
    }
}
