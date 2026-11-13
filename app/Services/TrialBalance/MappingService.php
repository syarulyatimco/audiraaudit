<?php

namespace App\Services\TrialBalance;

use App\Enums\MappingSource;
use App\Models\AccountMapping;
use App\Models\Engagement;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\EngagementGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Account → FSLI mapping. Mappings are keyed by account code, so they persist across re-imports
 * and apply to both current and prior year balances. Suggestions (carried forward from the
 * client's earlier engagements, or name rules) are never applied without a person accepting them.
 */
class MappingService
{
    public function __construct(private LeadScheduleService $schedules, private LeadPaperSync $leadPapers, private ActivityLogger $logger) {}

    /**
     * @param  array<string, ?int>  $map  account_code => engagement_fsli_id (null = unmap)
     */
    public function apply(Engagement $engagement, array $map, User $actor, MappingSource $source = MappingSource::Manual): int
    {
        EngagementGuard::assertEditable($engagement);
        $accounts = $this->schedules->accounts($engagement);
        $fsliIds = $engagement->fslis()->pluck('id')->all();

        foreach ($map as $code => $fsliId) {
            if (! $accounts->has((string) $code)) {
                throw ValidationException::withMessages(['mapping' => "Account {$code} is not in the engagement's trial balance."]);
            }
            if ($fsliId !== null && ! in_array((int) $fsliId, $fsliIds, true)) {
                throw ValidationException::withMessages(['mapping' => "Invalid FSLI for account {$code}."]);
            }
        }

        return DB::transaction(function () use ($engagement, $map, $actor, $source) {
            $existing = $engagement->accountMappings()->get()->keyBy('account_code');
            $changed = 0;
            foreach ($map as $code => $fsliId) {
                $code = (string) $code;
                $current = $existing->get($code);
                if ($fsliId === null) {
                    if ($current) {
                        $current->delete();
                        $changed++;
                    }

                    continue;
                }
                if ($current && $current->engagement_fsli_id === (int) $fsliId) {
                    continue;
                }
                $engagement->accountMappings()->updateOrCreate(['account_code' => $code], [
                    'engagement_fsli_id' => (int) $fsliId, 'source' => $source, 'mapped_by' => $actor->id,
                ]);
                $changed++;
            }
            if ($changed) {
                $this->logger->log('mapping.updated', "{$changed} account mapping(s) updated ({$source->label()})", $engagement, ['accounts' => array_keys($map)], $actor);
                $this->leadPapers->refresh($engagement, $actor, 'Account mapping changed');
            }

            return $changed;
        });
    }

    /**
     * Suggestions for unmapped accounts.
     *
     * @return array<string, array{fsli_id: int, source: string, reason: string}>
     */
    public function suggestions(Engagement $engagement): array
    {
        $accounts = $this->schedules->accounts($engagement)->whereNull('fsli_id');
        if ($accounts->isEmpty()) {
            return [];
        }
        $fslisByCode = $engagement->fslis()->pluck('id', 'code');

        // 1) Carried forward from this client's other engagements (same account code).
        $previous = AccountMapping::query()
            ->join('engagements', 'engagements.id', '=', 'account_mappings.engagement_id')
            ->join('engagement_fslis', 'engagement_fslis.id', '=', 'account_mappings.engagement_fsli_id')
            ->where('engagements.client_id', $engagement->client_id)
            ->where('engagements.id', '!=', $engagement->id)
            ->whereIn('account_mappings.account_code', $accounts->keys()->all())
            ->orderByDesc('engagements.period_end')
            ->get(['account_mappings.account_code', 'engagement_fslis.code as fsli_code', 'engagements.code as engagement_code'])
            ->unique('account_code')->keyBy('account_code');

        $rules = config('audira.trial_balance.mapping_rules');
        $out = [];
        foreach ($accounts as $code => $acc) {
            $prev = $previous->get($code);
            if ($prev && isset($fslisByCode[$prev->fsli_code])) {
                $out[$code] = ['fsli_id' => $fslisByCode[$prev->fsli_code], 'source' => MappingSource::CarriedForward->value, 'reason' => "Mapped to {$prev->fsli_code} in {$prev->engagement_code}"];

                continue;
            }
            foreach ($rules as $pattern => $fsliCode) {
                if (! @preg_match($pattern.'i', $acc['name'])) {
                    continue;
                }
                if ($fsliCode === null) {
                    break; // deliberately left for manual judgement
                }
                if (isset($fslisByCode[$fsliCode])) {
                    $out[$code] = ['fsli_id' => $fslisByCode[$fsliCode], 'source' => MappingSource::Rule->value, 'reason' => 'Account name matches '.$fsliCode.' rule'];
                    break; // first applicable match wins
                }
                // Target FSLI not used in this engagement's template: try the next rule.
            }
        }

        return $out;
    }

    /** @param list<string> $codes */
    public function acceptSuggestions(Engagement $engagement, array $codes, User $actor): int
    {
        $suggestions = array_intersect_key($this->suggestions($engagement), array_flip($codes));
        $total = 0;
        foreach ([MappingSource::CarriedForward, MappingSource::Rule] as $source) {
            $subset = array_map(fn ($s) => $s['fsli_id'], array_filter($suggestions, fn ($s) => $s['source'] === $source->value));
            if ($subset) {
                $total += $this->apply($engagement, $subset, $actor, $source);
            }
        }

        return $total;
    }
}
