<?php

namespace App\Services\ClientFs;

use App\Enums\FsliClassification as C;
use App\Models\ClientFinancialStatement;
use App\Models\ClientFsVersion;
use App\Models\Engagement;
use App\Models\User;
use App\Services\Methodology\MethodologyService;
use App\Services\TrialBalance\AmountParser;
use App\Services\TrialBalance\LeadScheduleService;
use App\Services\TrialBalance\SpreadsheetReader;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\Spreadsheets\XlsxWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Management-prepared financial statements (ADR-045). The CLIENT prepares and submits them in the
 * portal; the audit team only reviews: it compares them with the audited figures (tie-out),
 * requests changes, and accepts one director-approved version that the audit report refers to.
 * Nothing on the firm side can write the client's statements or notes.
 */
class ClientFsService
{
    public const KINDS = ['heading', 'line', 'subtotal'];

    public const MAX_ROWS = 300;

    private const EXPENSE_CLASSES = [C::CostOfSales, C::Expense, C::FinanceCost, C::TaxExpense];

    public function __construct(
        private ActivityLogger $logger,
        private LeadScheduleService $schedules,
        private XlsxWriter $writer,
        private SpreadsheetReader $reader,
    ) {}

    // ───────────────────────────── client (portal) side ─────────────────────────────

    public function start(Engagement $engagement, string $template, User $client): ClientFinancialStatement
    {
        $this->assertClient($engagement, $client);
        $t = config("fs_templates.{$template}") ?? throw ValidationException::withMessages(['template' => 'Choose a layout.']);
        if ($engagement->clientFinancialStatement()->exists()) {
            throw ValidationException::withMessages(['template' => 'The financial statements for this audit have already been started.']);
        }
        $statements = [];
        foreach (ClientFinancialStatement::STATEMENTS as $key) {
            $statements[$key] = [
                'label' => $t['statements'][$key]['label'],
                'rows' => array_map(fn ($r) => ['id' => $this->newId(), 'kind' => $r[0], 'caption' => $r[1], 'side' => $key === 'sofp' ? ($r[2] ?? 'asset') : null, 'note' => '', 'cy' => null, 'py' => null], $t['statements'][$key]['rows']),
            ];
        }
        $fs = ClientFinancialStatement::create([
            'engagement_id' => $engagement->id,
            'template' => $template,
            'statements' => $statements,
            'notes' => array_map(fn ($title) => ['id' => $this->newId(), 'title' => $title, 'body' => ''], $t['notes']),
            'updated_by' => $client->id,
        ]);
        $this->logger->log('client_fs.started', "Management started the financial statements ({$t['label']})", $fs, [], $client);

        return $fs;
    }

    /** @param list<array<string, mixed>> $rows */
    public function saveStatement(ClientFinancialStatement $fs, string $key, array $rows, User $client): void
    {
        $this->assertDraftable($fs, $client);
        if (! in_array($key, ClientFinancialStatement::STATEMENTS, true)) {
            throw ValidationException::withMessages(['statement' => 'Unknown statement.']);
        }
        $statements = $fs->statements;
        $statements[$key]['rows'] = $this->cleanRows($key, $rows);
        $fs->update(['statements' => $statements, 'updated_by' => $client->id]);
    }

    /** @param list<array<string, mixed>> $notes */
    public function saveNotes(ClientFinancialStatement $fs, array $notes, User $client): void
    {
        $this->assertDraftable($fs, $client);
        if (count($notes) > 100) {
            throw ValidationException::withMessages(['notes' => 'Up to 100 notes.']);
        }
        $clean = [];
        foreach (array_values($notes) as $i => $n) {
            $title = trim((string) ($n['title'] ?? ''));
            $body = str_replace("\r\n", "\n", (string) ($n['body'] ?? ''));
            if ($title === '' && trim($body) === '') {
                continue;
            }
            if ($title === '' || mb_strlen($title) > 200 || mb_strlen($body) > 30000) {
                throw ValidationException::withMessages(['notes' => 'Note '.($i + 1).' needs a title (up to 200 characters) and up to 30,000 characters of text.']);
            }
            $clean[] = ['id' => $this->validId($n['id'] ?? null), 'title' => $title, 'body' => $body];
        }
        $fs->update(['notes' => $clean, 'updated_by' => $client->id]);
    }

    public function submit(ClientFinancialStatement $fs, User $client, bool $directorsApproved, ?string $names, ?string $approvedOn): ClientFsVersion
    {
        $this->assertDraftable($fs, $client);
        $content = ['template' => $fs->template, 'statements' => $fs->statements, 'notes' => $fs->notes];
        $checks = $this->checks($content);
        if ($directorsApproved) {
            if (blank($names) || blank($approvedOn)) {
                throw ValidationException::withMessages(['approved_by_names' => 'Give the names of the approving directors and the date of approval.']);
            }
            if (strtotime((string) $approvedOn) === false || strtotime((string) $approvedOn) > time()) {
                throw ValidationException::withMessages(['approved_on' => 'The approval date cannot be in the future.']);
            }
            if (! $checks['sofp_balanced']) {
                throw ValidationException::withMessages(['submit' => 'The statement of financial position does not balance. Correct it before submitting the approved version.']);
            }
        }

        return DB::transaction(function () use ($fs, $client, $content, $directorsApproved, $names, $approvedOn) {
            ClientFinancialStatement::whereKey($fs->id)->lockForUpdate()->first();
            $fs->versions()->where('review_status', 'received')->update(['review_status' => 'superseded']); // unreviewed versions are replaced; reviewed ones keep their outcome
            $v = ClientFsVersion::create([
                'statement_id' => $fs->id,
                'version' => (int) $fs->versions()->max('version') + 1,
                'content' => $content,
                'content_sha256' => hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE)),
                'submitted_by' => $client->id,
                'submitted_at' => now(),
                'directors_approved' => $directorsApproved,
                'approved_by_names' => $directorsApproved ? mb_substr(trim($names), 0, 300) : null,
                'approved_on' => $directorsApproved ? $approvedOn : null,
            ]);
            $fs->forceFill(['status' => 'submitted'])->save();
            $this->logger->log('client_fs.submitted', "Management submitted version {$v->version} of the financial statements".($directorsApproved ? ' (approved by the directors)' : ' (draft)'), $v, ['sha256' => $v->content_sha256], $client);

            return $v;
        });
    }

    // ───────────────────────────── audit team side ─────────────────────────────

    public function review(ClientFsVersion $v, string $decision, ?string $comment, User $auditor): void
    {
        $fs = $v->statement;
        $engagement = $fs->engagement;
        $this->assertReviewer($engagement, $auditor);
        if ($fs->latestVersion()?->id !== $v->id || $v->review_status !== 'received') {
            throw ValidationException::withMessages(['review' => 'Only the latest version awaiting review can be reviewed.']);
        }
        $comment = filled($comment) ? mb_substr(trim($comment), 0, 5000) : null;

        if ($decision === 'request_changes') {
            if (! $comment) {
                throw ValidationException::withMessages(['comment' => 'Tell management what needs to change.']);
            }
            DB::transaction(function () use ($v, $fs, $comment, $auditor) {
                $v->forceFill(['review_status' => 'changes_requested', 'review_comment' => $comment, 'reviewed_by' => $auditor->id, 'reviewed_at' => now()])->save();
                $fs->forceFill(['status' => 'changes_requested'])->save();
            });
            $this->logger->log('client_fs.changes_requested', "Changes requested on version {$v->version} of management's financial statements", $v, [], $auditor);

            return;
        }
        if ($decision !== 'accept') {
            throw ValidationException::withMessages(['decision' => 'Unknown decision.']);
        }
        if (! $v->directors_approved) {
            throw ValidationException::withMessages(['review' => 'Only a version approved by the directors can be accepted for the audit report.']);
        }
        $checks = $this->checks($v->content);
        if (! $checks['sofp_balanced']) {
            throw ValidationException::withMessages(['review' => 'The statement of financial position in this version does not balance.']);
        }
        $tie = $this->tieout($fs, $v->content);
        if (($tie['differences'] > 0 || $tie['unmapped'] > 0) && ! $comment) {
            throw ValidationException::withMessages(['comment' => "{$tie['differences']} line(s) differ from the audited figures and {$tie['unmapped']} are not tied out. Explain why the version is acceptable."]);
        }
        DB::transaction(function () use ($v, $fs, $comment, $auditor) {
            $v->forceFill(['review_status' => 'accepted', 'review_comment' => $comment, 'reviewed_by' => $auditor->id, 'reviewed_at' => now()])->save();
            $fs->forceFill(['status' => 'accepted'])->save();
        });
        $this->logger->log('client_fs.accepted', "Version {$v->version} of management's financial statements accepted for the audit report", $v, ['sha256' => $v->content_sha256, 'differences' => $tie['differences'], 'unmapped' => $tie['unmapped']], $auditor);
    }

    /** Re-opens accepted statements (e.g. an adjusting event) before the report is signed. */
    public function reopen(ClientFinancialStatement $fs, string $reason, User $auditor): void
    {
        $this->assertReviewer($fs->engagement, $auditor);
        if ($fs->status !== 'accepted') {
            throw ValidationException::withMessages(['reopen' => 'Only accepted statements can be re-opened.']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Give the reason for re-opening.']);
        }
        DB::transaction(function () use ($fs, $reason, $auditor) {
            $v = $fs->acceptedVersion();
            $v?->forceFill(['review_status' => 'superseded', 'review_comment' => trim(($v->review_comment ? $v->review_comment."\n" : '').'Re-opened: '.trim($reason))])->save();
            $fs->forceFill(['status' => 'changes_requested'])->save();
            $this->logger->log('client_fs.reopened', 'Accepted financial statements re-opened: '.Str::limit($reason, 200), $fs, [], $auditor);
        });
    }

    /** @param array<string, string> $map line id => comma-separated FSLI codes */
    public function saveTieout(ClientFinancialStatement $fs, array $map, User $auditor): void
    {
        $this->assertReviewer($fs->engagement, $auditor);
        $known = $fs->engagement->fslis()->pluck('code')->map(fn ($c) => strtoupper($c))->flip();
        $clean = [];
        foreach ($map as $id => $codes) {
            if (! preg_match('/^[a-z0-9]{8}$/', (string) $id)) {
                continue;
            }
            $list = collect(preg_split('/[\s,;]+/', strtoupper((string) $codes)))->filter()->unique()->values();
            $unknown = $list->reject(fn ($c) => $known->has($c));
            if ($unknown->isNotEmpty()) {
                throw ValidationException::withMessages(['tieout' => 'Unknown FSLI code(s): '.$unknown->join(', ')]);
            }
            $clean[$id] = $list->all();
        }
        $fs->forceFill(['tieout' => $clean])->save();
        $this->logger->log('client_fs.tieout_saved', 'Tie-out of management\'s financial statements updated', $fs, [], $auditor);
    }

    // ───────────────────────────── shared ─────────────────────────────

    /** @return array{sofp_assets: array{0:int,1:int}, sofp_el: array{0:int,1:int}, sofp_balanced: bool, sopl_result: array{0:int,1:int}, soce: array{0:int,1:int}, cfs: array{0:int,1:int}} */
    public function checks(array $content): array
    {
        $sum = function (string $key, ?string $side, string $col) use ($content) {
            return (int) collect($content['statements'][$key]['rows'] ?? [])
                ->filter(fn ($r) => $r['kind'] === 'line' && ($side === null || ($r['side'] ?? null) === $side))
                ->sum(fn ($r) => (int) ($r[$col] ?? 0));
        };
        $assets = [$sum('sofp', 'asset', 'cy'), $sum('sofp', 'asset', 'py')];
        $el = [$sum('sofp', 'el', 'cy'), $sum('sofp', 'el', 'py')];

        return [
            'sofp_assets' => $assets, 'sofp_el' => $el,
            'sofp_balanced' => $assets[0] === $el[0] && $assets[1] === $el[1] && ($assets[0] !== 0 || $el[0] !== 0),
            'sopl_result' => [$sum('sopl', null, 'cy'), $sum('sopl', null, 'py')],
            'soce' => [$sum('soce', null, 'cy'), $sum('soce', null, 'py')],
            'cfs' => [$sum('cfs', null, 'cy'), $sum('cfs', null, 'py')],
        ];
    }

    /**
     * Compares each SOFP / P&L line with the audited (adjusted TB) figure of the FSLIs it maps to.
     * Unmapped lines are matched by caption to an FSLI name where possible.
     *
     * @return array{lines: list<array<string, mixed>>, differences: int, unmapped: int}
     */
    public function tieout(ClientFinancialStatement $fs, ?array $content = null): array
    {
        $content ??= ['statements' => $fs->statements];
        $schedules = collect($this->schedules->build($fs->engagement)['fslis'])->keyBy(fn ($s) => strtoupper($s['fsli']->code));
        $byName = $schedules->mapWithKeys(fn ($s, $code) => [$this->normal($s['fsli']->name) => $code]);
        $lines = [];
        $diff = 0;
        $unmapped = 0;
        foreach (['sofp', 'sopl'] as $key) {
            foreach ($content['statements'][$key]['rows'] ?? [] as $r) {
                if ($r['kind'] !== 'line') {
                    continue;
                }
                $codes = $fs->tieout[$r['id']] ?? null;
                $guessed = false;
                if ($codes === null) {
                    $n = $this->normal($r['caption']);
                    // Exact caption match first, then an FSLI name that starts with the caption
                    // ("Retained earnings" → "Retained earnings / (accumulated losses)").
                    $g = $byName->get($n) ?? ($n !== '' ? $byName->first(fn ($code, $name) => str_starts_with($name, $n.' ')) : null);
                    $codes = $g ? [$g] : [];
                    $guessed = (bool) $g;
                }
                $audited = [0, 0];
                foreach ($codes as $code) {
                    if ($s = $schedules->get($code)) {
                        $sign = $key === 'sopl' && in_array($s['fsli']->classification, self::EXPENSE_CLASSES, true) ? -1 : 1;
                        $audited[0] += $sign * (int) $s['cy_adj'];
                        $audited[1] += $sign * (int) $s['py'];
                    }
                }
                $client = [(int) ($r['cy'] ?? 0), (int) ($r['py'] ?? 0)];
                $hasAmount = $client[0] !== 0 || $client[1] !== 0;
                $status = ! $codes ? ($hasAmount ? 'unmapped' : 'empty') : ($client === $audited ? 'agreed' : 'difference');
                $diff += $status === 'difference' ? 1 : 0;
                $unmapped += $status === 'unmapped' ? 1 : 0;
                $lines[] = ['statement' => $key, 'id' => $r['id'], 'caption' => $r['caption'], 'codes' => $codes, 'guessed' => $guessed,
                    'client' => $client, 'audited' => $audited, 'difference' => [$client[0] - $audited[0], $client[1] - $audited[1]], 'status' => $status];
            }
        }

        return ['lines' => $lines, 'differences' => $diff, 'unmapped' => $unmapped];
    }

    public const XLSX_HEADERS = ['Statement', 'Kind', 'Side', 'Caption', 'Note', 'Current year', 'Prior year', 'Line ID (do not edit)'];

    public function export(ClientFinancialStatement $fs, ?array $content = null): string
    {
        $content ??= ['statements' => $fs->statements];
        $rows = [];
        foreach (ClientFinancialStatement::STATEMENTS as $key) {
            foreach ($content['statements'][$key]['rows'] ?? [] as $r) {
                $rows[] = [$key, $r['kind'], $r['side'] ?? '', $r['caption'], $r['note'] ?? '',
                    $r['cy'] === null ? '' : AmountParser::format((int) $r['cy']), $r['py'] === null ? '' : AmountParser::format((int) $r['py']), $r['id']];
            }
        }
        $e = $fs->engagement;
        $path = tempnam(sys_get_temp_dir(), 'cfs').'.xlsx';
        $this->writer->write($path, 'Financial statements', self::XLSX_HEADERS, $rows, [12, 10, 7, 50, 7, 16, 16, 14], [7], [
            $e->client->name.' — financial statements for '.$e->periodLabel(),
            'Statement: sofp, sopl, soce, cfs · Kind: heading, line, subtotal · Side (sofp only): asset or el · Expenses and outflows in brackets.',
        ]);

        return $path;
    }

    /** @return array{statements: int, rows: int} */
    public function import(ClientFinancialStatement $fs, string $path, User $client): array
    {
        $this->assertDraftable($fs, $client);
        [$map, $rows] = app(MethodologyService::class)->locateHeader(
            $this->reader->read($path, 'xlsx', 2000, 500, 'the financial statements'),
            ['statement' => 'statement', 'kind' => 'kind', 'side' => 'side', 'caption' => 'caption', 'note' => 'note', 'current year' => 'cy', 'prior year' => 'py', 'line id (do not edit)' => 'id'],
            'caption',
        );
        $by = [];
        foreach ($rows as $i => $line) {
            $get = fn (string $f) => isset($map[$f]) ? trim((string) ($line[$map[$f]] ?? '')) : '';
            if ($get('caption') === '' && $get('cy') === '' && $get('py') === '') {
                continue;
            }
            $key = strtolower($get('statement'));
            if (! in_array($key, ClientFinancialStatement::STATEMENTS, true)) {
                throw ValidationException::withMessages(['file' => 'Row '.($i + 1).': the Statement column must be sofp, sopl, soce or cfs.']);
            }
            $by[$key][] = ['id' => $get('id'), 'kind' => strtolower($get('kind')) ?: 'line', 'side' => strtolower($get('side')) ?: null, 'caption' => $get('caption'), 'note' => $get('note'), 'cy' => $get('cy'), 'py' => $get('py')];
        }
        if ($by === []) {
            throw ValidationException::withMessages(['file' => 'No statement rows were found in the file.']);
        }
        $statements = $fs->statements;
        $count = 0;
        foreach ($by as $key => $list) {
            $statements[$key]['rows'] = $this->cleanRows($key, $list);
            $count += count($list);
        }
        $fs->update(['statements' => $statements, 'updated_by' => $client->id]);
        $this->logger->log('client_fs.imported', 'Management updated the financial statements from Excel', $fs, ['statements' => array_keys($by), 'rows' => $count], $client);

        return ['statements' => count($by), 'rows' => $count];
    }

    /** @return list<array<string, mixed>> */
    private function cleanRows(string $key, array $rows): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => 'A statement can have up to '.self::MAX_ROWS.' rows.']);
        }
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $n = $i + 1;
            $kind = (string) ($r['kind'] ?? 'line');
            $caption = trim((string) ($r['caption'] ?? ''));
            if (! in_array($kind, self::KINDS, true)) {
                throw ValidationException::withMessages(['rows' => "Row {$n}: kind must be heading, line or subtotal."]);
            }
            if ($caption === '' || mb_strlen($caption) > 200) {
                throw ValidationException::withMessages(['rows' => "Row {$n}: a caption of up to 200 characters is required."]);
            }
            $side = null;
            if ($key === 'sofp') {
                $side = (string) ($r['side'] ?? '');
                if (! in_array($side, ['asset', 'el'], true)) {
                    throw ValidationException::withMessages(['rows' => "Row {$n} ({$caption}): choose Assets or Equity & liabilities."]);
                }
            }
            $amount = function (string $col) use ($r, $n, $caption, $kind) {
                $v = $r[$col] ?? null;
                if ($kind === 'heading' || $v === null || (is_string($v) && trim($v) === '')) {
                    return null;
                }
                if (is_int($v)) {
                    return $v; // already in sen (from the editor)
                }
                try {
                    return AmountParser::toSen($v);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['rows' => "Row {$n} ({$caption}): \"{$v}\" is not an amount."]);
                }
            };
            $out[] = [
                'id' => $this->validId($r['id'] ?? null), 'kind' => $kind, 'caption' => $caption, 'side' => $side,
                'note' => mb_substr(trim((string) ($r['note'] ?? '')), 0, 20), 'cy' => $amount('cy'), 'py' => $amount('py'),
            ];
        }

        return $out;
    }

    public function assertClient(Engagement $engagement, User $client): void
    {
        if (! $client->isClientUser() || $client->client_id !== $engagement->client_id) {
            throw ValidationException::withMessages(['fs' => 'Only management (client portal users) prepare the financial statements.']);
        }
        if ($engagement->status->isLocked()) {
            throw ValidationException::withMessages(['fs' => 'This audit is closed.']);
        }
    }

    private function assertDraftable(ClientFinancialStatement $fs, User $client): void
    {
        $this->assertClient($fs->engagement, $client);
        if ($fs->status === 'accepted') {
            throw ValidationException::withMessages(['fs' => 'The audit team has accepted these financial statements. Ask them to re-open them if a change is needed.']);
        }
    }

    private function assertReviewer(Engagement $engagement, User $auditor): void
    {
        if (! Permissions::allowsOnEngagement($auditor, $engagement, 'engagement.prepare_report')) {
            throw ValidationException::withMessages(['review' => 'Only the engagement partner or manager reviews management\'s financial statements.']);
        }
        if ($engagement->status->isLocked()) {
            throw ValidationException::withMessages(['review' => 'This engagement is locked.']);
        }
    }

    private function newId(): string
    {
        return Str::lower(Str::random(8));
    }

    private function validId(mixed $id): string
    {
        return is_string($id) && preg_match('/^[a-z0-9]{8}$/', $id) ? $id : $this->newId();
    }

    private function normal(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
    }

    /** Totals shown under each statement (sum of "line" rows). */
    public function totals(array $rows, ?string $side = null): array
    {
        $c = collect($rows)->filter(fn ($r) => $r['kind'] === 'line' && ($side === null || ($r['side'] ?? null) === $side));

        return [(int) $c->sum(fn ($r) => (int) ($r['cy'] ?? 0)), (int) $c->sum(fn ($r) => (int) ($r['py'] ?? 0))];
    }
}
