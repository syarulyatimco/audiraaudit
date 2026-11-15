<?php

namespace App\Services\Ledger;

use App\Models\Engagement;
use App\Models\GlImport;
use App\Models\JetTest;
use App\Models\JetTestItem;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Journal entry testing over the imported GL (ADR-049). Each enabled criterion flags entries; an
 * entry is selected when it meets at least `min_matches` criteria. The criteria are the team's
 * judgement — nothing here is presented as a required set. A saved test freezes the selection,
 * records a result per entry and a conclusion.
 */
class JournalTestingService
{
    public const MAX_ITEMS = 500;

    public const LABELS = [
        'weekend' => 'Posted on a weekend',
        'dates' => 'Posted on a flagged date',
        'year_end' => 'Near the period end',
        'round' => 'Round amount',
        'threshold' => 'At or above the amount threshold',
        'users' => 'Posted by a flagged user',
        'rare_users' => 'Posted by an infrequent user',
        'journal_types' => 'Flagged journal type',
        'keywords' => 'Description keyword',
        'accounts' => 'Flagged account',
    ];

    public function __construct(private ActivityLogger $logger) {}

    /** Normalises criteria from a form or query string. */
    public function criteria(array $in, Engagement $engagement): array
    {
        $list = fn ($v) => array_values(array_unique(array_filter(array_map('trim', is_array($v) ? $v : preg_split('/[,;\n]+/', (string) $v)), fn ($x) => $x !== '')));
        $dates = array_values(array_filter(array_map(fn ($d) => LedgerFiles::date($d), $list($in['dates'] ?? ''))));

        return [
            'weekend' => filter_var($in['weekend'] ?? false, FILTER_VALIDATE_BOOL),
            'dates' => $dates,
            'year_end_days' => max(0, min(60, (int) ($in['year_end_days'] ?? 0))),
            'round_rm' => max(0, (int) ($in['round_rm'] ?? 0)),
            'threshold_rm' => max(0, (float) str_replace(',', '', (string) ($in['threshold_rm'] ?? 0))),
            'users' => array_slice($list($in['users'] ?? ''), 0, 30),
            'rare_users_max' => max(0, min(100, (int) ($in['rare_users_max'] ?? 0))),
            'journal_types' => array_slice($list($in['journal_types'] ?? ''), 0, 30),
            'keywords' => array_slice(array_map(fn ($k) => mb_substr($k, 0, 40), $list($in['keywords'] ?? '')), 0, 30),
            'accounts' => array_slice($list($in['accounts'] ?? ''), 0, 100),
            'min_matches' => max(1, min(10, (int) ($in['min_matches'] ?? 1))),
            'period_end' => $engagement->period_end->toDateString(),
        ];
    }

    /** SQL flag expressions for the enabled criteria: key => [sql, bindings]. */
    private function flags(array $c, GlImport $gl): array
    {
        $f = [];
        if ($c['weekend']) {
            $f['weekend'] = ['extract(isodow from posted_on) in (6, 7)', []];
        }
        if ($c['dates']) {
            $f['dates'] = ['posted_on in ('.implode(',', array_fill(0, count($c['dates']), '?::date')).')', $c['dates']];
        }
        if ($c['year_end_days'] > 0) {
            $f['year_end'] = ['posted_on between (?::date - ?::int) and (?::date + ?::int)', [$c['period_end'], $c['year_end_days'], $c['period_end'], $c['year_end_days']]];
        }
        if ($c['round_rm'] > 0) {
            $f['round'] = ['(amount_sen <> 0 and abs(amount_sen) % ? = 0)', [$c['round_rm'] * 100]];
        }
        if ($c['threshold_rm'] > 0) {
            $f['threshold'] = ['abs(amount_sen) >= ?', [(int) round($c['threshold_rm'] * 100)]];
        }
        if ($c['users']) {
            $f['users'] = ['lower(posted_by) in ('.implode(',', array_fill(0, count($c['users']), '?')).')', array_map('mb_strtolower', $c['users'])];
        }
        if ($c['rare_users_max'] > 0) {
            $f['rare_users'] = ['posted_by in (select posted_by from gl_lines g2 where g2.gl_import_id = ? and g2.posted_by is not null group by posted_by having count(*) <= ?)', [$gl->id, $c['rare_users_max']]];
        }
        if ($c['journal_types']) {
            $f['journal_types'] = ['lower(journal_type) in ('.implode(',', array_fill(0, count($c['journal_types']), '?')).')', array_map('mb_strtolower', $c['journal_types'])];
        }
        if ($c['keywords']) {
            $f['keywords'] = ['('.implode(' or ', array_fill(0, count($c['keywords']), "description ilike ? escape '!'")).')', array_map(fn ($k) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $k).'%', $c['keywords'])];
        }
        if ($c['accounts']) {
            $f['accounts'] = ['account_code in ('.implode(',', array_fill(0, count($c['accounts']), '?')).')', $c['accounts']];
        }

        return $f;
    }

    /** @return array{total: int, rows: \Illuminate\Support\Collection, enabled: list<string>} */
    public function candidates(GlImport $gl, array $c, int $limit = 300): array
    {
        $flags = $this->flags($c, $gl);
        if (! $flags) {
            return ['total' => 0, 'rows' => collect(), 'enabled' => []];
        }
        $select = [];
        $bind = [];
        foreach ($flags as $key => [$sql, $b]) {
            $select[] = "(case when {$sql} then 1 else 0 end) as f_{$key}";
            array_push($bind, ...$b);
        }
        $sum = implode(' + ', array_map(fn ($k) => "f_{$k}", array_keys($flags)));
        $inner = 'select id, seq, account_code, account_name, posted_on, reference, description, debit_sen, credit_sen, amount_sen, journal_type, posted_by, '.implode(', ', $select)
            .' from gl_lines where gl_import_id = ? and not is_opening';
        $bind[] = $gl->id;
        $base = "from ({$inner}) x where ({$sum}) >= ?";
        $total = (int) DB::selectOne("select count(*) as n {$base}", [...$bind, $c['min_matches']])->n;
        $rows = collect(DB::select("select x.*, ({$sum}) as matches {$base} order by matches desc, abs(amount_sen) desc, seq limit ?", [...$bind, $c['min_matches'], $limit]))
            ->map(function ($r) use ($flags) {
                $r->reasons = array_values(array_filter(array_keys($flags), fn ($k) => (int) $r->{"f_{$k}"} === 1));

                return $r;
            });

        return ['total' => $total, 'rows' => $rows, 'enabled' => array_keys($flags)];
    }

    public function save(Engagement $engagement, array $criteria, string $title, User $actor): JetTest
    {
        $this->authorize($engagement, $actor, 'engagement.manage_programme');
        $gl = $engagement->activeGlImport() ?? throw ValidationException::withMessages(['jet' => 'Import the general ledger first.']);
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw ValidationException::withMessages(['title' => 'Give the test a title (up to 200 characters).']);
        }
        $found = $this->candidates($gl, $criteria, self::MAX_ITEMS + 1);
        if ($found['enabled'] === []) {
            throw ValidationException::withMessages(['jet' => 'Choose at least one criterion.']);
        }
        if ($found['total'] === 0) {
            throw ValidationException::withMessages(['jet' => 'No entries meet these criteria.']);
        }
        if ($found['total'] > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['jet' => "{$found['total']} entries meet these criteria. Narrow them to ".self::MAX_ITEMS.' or fewer (e.g. raise the minimum criteria met or the threshold) before saving.']);
        }

        return DB::transaction(function () use ($engagement, $gl, $criteria, $title, $found, $actor) {
            $n = (int) $engagement->jetTests()->count() + 1;
            $test = JetTest::create(['engagement_id' => $engagement->id, 'gl_import_id' => $gl->id, 'reference' => sprintf('JET-%02d', $n), 'title' => $title,
                'criteria' => $criteria + ['entries_found' => $found['total']], 'created_by' => $actor->id]);
            foreach ($found['rows'] as $r) {
                JetTestItem::create(['jet_test_id' => $test->id, 'gl_line_id' => $r->id, 'reasons' => $r->reasons]);
            }
            $this->logger->log('jet.saved', "Journal entry test {$test->reference} saved with {$found['total']} entries", $test, ['criteria' => $criteria], $actor);

            return $test;
        });
    }

    public function record(JetTestItem $item, string $result, ?string $note, ?string $evidenceUuid, User $actor): void
    {
        $test = $item->test;
        $this->authorize($test->engagement, $actor, 'engagement.execute_procedures');
        if ($test->status !== 'open') {
            throw ValidationException::withMessages(['item' => 'This test is concluded.']);
        }
        if (! in_array($result, ['no_exception', 'exception'], true)) {
            throw ValidationException::withMessages(['result' => 'Choose a result.']);
        }
        $note = filled($note) ? mb_substr(trim($note), 0, 2000) : null;
        if ($result === 'exception' && ! $note) {
            throw ValidationException::withMessages(['note' => 'Describe the exception.']);
        }
        $doc = $evidenceUuid ? $test->engagement->documents()->where('uuid', $evidenceUuid)->first() ?? throw ValidationException::withMessages(['evidence' => 'Unknown document.']) : null;
        $item->forceFill(['result' => $result, 'note' => $note, 'evidence_document_id' => $doc?->id, 'tested_by' => $actor->id, 'tested_at' => now()])->save();
    }

    public function conclude(JetTest $test, string $conclusion, User $actor): void
    {
        $this->authorize($test->engagement, $actor, 'engagement.manage_programme');
        if ($test->status !== 'open') {
            throw ValidationException::withMessages(['conclusion' => 'Already concluded.']);
        }
        if ($test->items()->whereNull('result')->exists()) {
            throw ValidationException::withMessages(['conclusion' => 'Record a result for every selected entry first.']);
        }
        if (trim($conclusion) === '') {
            throw ValidationException::withMessages(['conclusion' => 'Write the conclusion.']);
        }
        $test->forceFill(['status' => 'concluded', 'conclusion' => mb_substr(trim($conclusion), 0, 5000), 'concluded_by' => $actor->id, 'concluded_at' => now()])->save();
        $this->logger->log('jet.concluded', "Journal entry test {$test->reference} concluded", $test, ['exceptions' => $test->items()->where('result', 'exception')->count()], $actor);
    }

    private function authorize(Engagement $engagement, User $actor, string $permission): void
    {
        EngagementGuard::assertEditable($engagement);
        if (! Permissions::allowsOnEngagement($actor, $engagement, $permission)) {
            throw ValidationException::withMessages(['jet' => 'Your role on this engagement cannot do this.']);
        }
    }
}
