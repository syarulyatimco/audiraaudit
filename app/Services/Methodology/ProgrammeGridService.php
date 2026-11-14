<?php

namespace App\Services\Methodology;

use App\Enums\ProgrammeStage;
use App\Models\Engagement;
use App\Models\EngagementFsli;
use App\Models\MethodologyLibrary;
use App\Models\MethodologyProgramme;
use App\Models\ProgrammeRow;
use App\Models\ProgrammeSheet;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\Spreadsheets\XlsxWriter;
use App\Services\TrialBalance\SpreadsheetReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Engagement programme grids. Applying a methodology library routes each library programme to the
 * engagement (planning) or to the FSLIs it covers (controls / substantive) as an editable grid.
 * "Done by / Date" is the preparer's sign-off and the reviewer signs after; both are bound to the
 * row's content hash, so editing a signed row clears its sign-offs (kept in the activity log).
 */
class ProgrammeGridService
{
    /** Core grid columns, in order. done_by / done_at are filled by the sign-off, never typed. */
    public const COLUMNS = [
        'ref' => ['Ref', 20],
        'procedure' => ['Procedure', 4000],
        'assertions' => ['Assertion', 60],
        'risk' => ['Risk', 200],
        'done_by' => ['Done by', 0],
        'done_at' => ['Date', 0],
        'wp_ref' => ['WP ref', 2000],
        'conclusion' => ['Conclusion', 4000],
    ];

    public const MAX_CUSTOM_COLUMNS = 8;

    public function __construct(private ActivityLogger $logger, private XlsxWriter $writer, private SpreadsheetReader $reader, private MethodologyService $methodology) {}

    /** Library choice for an engagement; switching is allowed only while nothing has been signed. */
    public function apply(Engagement $engagement, MethodologyLibrary $library, User $actor): array
    {
        $this->assertOpen($engagement);
        if ($library->status !== 'active') {
            throw ValidationException::withMessages(['library' => 'Choose an active methodology library.']);
        }

        return DB::transaction(function () use ($engagement, $library, $actor) {
            if ($engagement->methodology_library_id && $engagement->methodology_library_id !== $library->id) {
                $signed = ProgrammeRow::whereIn('sheet_id', $engagement->programmeSheets()->select('id'))->whereNotNull('done_by')->count();
                if ($signed > 0) {
                    throw ValidationException::withMessages(['library' => "{$signed} programme row(s) are already signed. The methodology can no longer be changed for this engagement."]);
                }
                $sheetIds = $engagement->programmeSheets()->pluck('id');
                ProgrammeRow::whereIn('sheet_id', $sheetIds)->delete();
                ProgrammeSheet::whereIn('id', $sheetIds)->delete();
            }
            $previous = $engagement->methodology_library_id;
            $engagement->forceFill(['methodology_library_id' => $library->id, 'methodology_applied_at' => now()])->save();
            $result = $this->route($engagement, $library, $actor);
            $this->logger->log('programme.library_applied', "Methodology \"{$library->name}\" applied: {$result['created']} programme sheet(s) created", $engagement, ['library' => $library->uuid, 'previous_library_id' => $previous, ...$result], $actor);

            return $result;
        });
    }

    /** Adds sheets for FSLIs mapped since the library was applied. Existing sheets are never touched. */
    public function refresh(Engagement $engagement, User $actor): array
    {
        $this->assertOpen($engagement);
        $library = $engagement->methodologyLibrary ?? throw ValidationException::withMessages(['library' => 'Choose a methodology library first.']);

        return DB::transaction(function () use ($engagement, $library, $actor) {
            $result = $this->route($engagement, $library, $actor);
            $this->logger->log('programme.refreshed', "Programme routing refreshed: {$result['created']} new sheet(s)", $engagement, $result, $actor);

            return $result;
        });
    }

    /**
     * FSLIs in scope are those with at least one mapped account. Routing:
     *  planning   → one sheet per engagement-scope programme;
     *  fsli       → one sheet, attached to the first listed FSLI code present in the engagement;
     *  classification → every in-scope FSLI of that classification that has no code-specific
     *                   programme for the same stage.
     *
     * @return array{created: int, unrouted: list<string>}
     */
    private function route(Engagement $engagement, MethodologyLibrary $library, User $actor): array
    {
        $fslis = $engagement->fslis()->whereHas('mappings')->orderBy('sort_order')->get();
        $byCode = $fslis->keyBy('code');
        $programmes = $library->programmes()->with('steps')->get();
        $existing = $engagement->programmeSheets()->get(['source_programme_id', 'engagement_fsli_id', 'stage'])
            ->map(fn ($s) => $s->source_programme_id.'|'.$s->engagement_fsli_id)->flip();

        $created = 0;
        $covered = []; // stage => [fsli_id => true]
        $make = function (MethodologyProgramme $p, ?EngagementFsli $fsli) use ($engagement, $actor, $existing, &$created) {
            if ($existing->has($p->id.'|'.$fsli?->id)) {
                return;
            }
            $this->createSheet($engagement, $p, $fsli, $actor);
            $created++;
        };

        foreach ($programmes->where('scope', 'engagement') as $p) {
            $make($p, null);
        }
        foreach ($programmes->where('scope', 'fsli') as $p) {
            $target = collect($p->fsli_codes)->map(fn ($c) => $byCode->get($c))->filter()->first();
            foreach (collect($p->fsli_codes)->map(fn ($c) => $byCode->get($c))->filter() as $f) {
                $covered[$p->stage->value][$f->id] = true;
            }
            if ($target) {
                $make($p, $target);
            }
        }
        foreach ($programmes->where('scope', 'classification') as $p) {
            foreach ($fslis->filter(fn ($f) => $f->classification === $p->fsli_classification && ! isset($covered[$p->stage->value][$f->id])) as $f) {
                $covered[$p->stage->value][$f->id] = true;
                $make($p, $f);
            }
        }

        $unrouted = $fslis->filter(fn ($f) => ! isset($covered[ProgrammeStage::Substantive->value][$f->id]))->map(fn ($f) => $f->code)->values()->all();

        return ['created' => $created, 'unrouted' => $unrouted];
    }

    private function createSheet(Engagement $engagement, MethodologyProgramme $p, ?EngagementFsli $fsli, User $actor): ProgrammeSheet
    {
        $reference = $this->uniqueReference($engagement, $fsli ? $fsli->code.'-'.$p->stage->suffix() : $p->ref_prefix);
        $sheet = ProgrammeSheet::create([
            'engagement_id' => $engagement->id,
            'engagement_fsli_id' => $fsli?->id,
            'stage' => $p->stage,
            'reference' => $reference,
            'title' => ! $fsli ? $p->title : (mb_strtolower($fsli->name) === mb_strtolower($p->title) ? $fsli->name : $fsli->name.' — '.$p->title),
            'objective' => $p->objective,
            'source_programme_id' => $p->id,
            'columns' => [],
            'sort_order' => $this->sortOrder($p->stage, $fsli, $p),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        foreach ($p->steps->values() as $i => $s) {
            ProgrammeRow::create([
                'sheet_id' => $sheet->id,
                'position' => ($i + 1) * 10,
                'ref' => $reference.'.'.sprintf('%02d', $i + 1),
                'procedure' => $s->procedure,
                'assertions' => $s->assertions,
                'isa_refs' => $s->isa_refs,
                'guidance' => $s->guidance,
                'extra' => [],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        return $sheet;
    }

    public function addSheet(Engagement $engagement, array $data, User $actor): ProgrammeSheet
    {
        $this->assertOpen($engagement);
        $stage = ProgrammeStage::tryFrom((string) ($data['stage'] ?? '')) ?? throw ValidationException::withMessages(['stage' => 'Choose the stage.']);
        $fsli = filled($data['engagement_fsli_id'] ?? null) ? $engagement->fslis()->whereKey($data['engagement_fsli_id'])->first() : null;
        if (filled($data['engagement_fsli_id'] ?? null) && ! $fsli) {
            throw ValidationException::withMessages(['engagement_fsli_id' => 'Unknown FSLI.']);
        }
        if ($stage !== ProgrammeStage::Planning && ! $fsli) {
            throw ValidationException::withMessages(['engagement_fsli_id' => 'Controls and substantive programmes belong to an FSLI.']);
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw ValidationException::withMessages(['title' => 'Give the programme a title (up to 200 characters).']);
        }
        $reference = $this->uniqueReference($engagement, $fsli ? $fsli->code.'-'.$stage->suffix() : 'A-'.$stage->suffix());
        $sheet = ProgrammeSheet::create([
            'engagement_id' => $engagement->id, 'engagement_fsli_id' => $fsli?->id, 'stage' => $stage,
            'reference' => $reference, 'title' => $title, 'columns' => [],
            'sort_order' => $this->sortOrder($stage, $fsli, null),
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $this->addRow($sheet, null, $actor);
        $this->logger->log('programme.sheet_added', "Programme sheet {$reference} added", $sheet, [], $actor);

        return $sheet;
    }

    public function addRow(ProgrammeSheet $sheet, ?ProgrammeRow $after, User $actor): ProgrammeRow
    {
        $this->assertOpen($sheet->engagement);

        return DB::transaction(function () use ($sheet, $after, $actor) {
            $rows = $sheet->rows()->lockForUpdate()->get();
            $at = $after ? $rows->search(fn ($r) => $r->id === $after->id) + 1 : $rows->count();
            $row = new ProgrammeRow([
                'sheet_id' => $sheet->id, 'ref' => $this->nextRef($sheet, $rows), 'procedure' => '', 'extra' => [],
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $ordered = $rows->all();
            array_splice($ordered, $at, 0, [$row]);
            foreach ($ordered as $i => $r) {
                // Position changes do not alter the signed content, so sign-offs survive reordering.
                $r->position = ($i + 1) * 10;
                $r->save();
            }

            return $row;
        });
    }

    public function updateCell(ProgrammeRow $row, string $field, ?string $value, User $actor): ProgrammeRow
    {
        $sheet = $row->sheet;
        $this->assertOpen($sheet->engagement);
        $value = trim(str_replace("\r\n", "\n", (string) $value));

        if (str_starts_with($field, 'x:')) {
            $key = substr($field, 2);
            if (! collect($sheet->customColumns())->contains('key', $key)) {
                throw ValidationException::withMessages(['field' => 'Unknown column.']);
            }
            if (mb_strlen($value) > 2000) {
                throw ValidationException::withMessages(['value' => 'The text is too long for this column.']);
            }
            $extra = $row->extra ?? [];
            if ($value === '') {
                unset($extra[$key]);
            } else {
                $extra[$key] = $value;
            }
            $row->extra = $extra;
        } else {
            if (! in_array($field, ProgrammeRow::EDITABLE, true)) {
                throw ValidationException::withMessages(['field' => 'That column is filled by signing off, not by typing.']);
            }
            if (mb_strlen($value) > self::COLUMNS[$field][1]) {
                throw ValidationException::withMessages(['value' => 'The text is too long for this column.']);
            }
            $row->{$field} = $value === '' && ! in_array($field, ['ref', 'procedure'], true) ? null : $value;
        }

        if (! $row->isDirty()) {
            return $row;
        }

        return DB::transaction(function () use ($row, $field, $actor) {
            $cleared = $row->isDone();
            if ($cleared) {
                $this->logger->log('programme.signoffs_cleared', "Sign-offs on {$row->ref} cleared by an edit to ".$this->label($row->sheet, $field), $row, [
                    'done_by' => $row->done_by, 'done_at' => $row->done_at?->toIso8601String(),
                    'reviewed_by' => $row->reviewed_by, 'reviewed_at' => $row->reviewed_at?->toIso8601String(),
                    'signed_hash' => $row->signed_hash,
                ], $actor);
                $row->forceFill(['done_by' => null, 'done_at' => null, 'signed_hash' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            }
            $row->updated_by = $actor->id;
            $row->save();

            return $row;
        });
    }

    public function deleteRow(ProgrammeRow $row, User $actor): void
    {
        $this->assertOpen($row->sheet->engagement);
        if ($row->isDone()) {
            throw ValidationException::withMessages(['row' => 'A signed row cannot be deleted. Edit it instead (this clears the sign-offs).']);
        }
        $this->logger->log('programme.row_deleted', "Programme row {$row->ref} deleted", $row->sheet, ['ref' => $row->ref, 'procedure' => Str::limit($row->procedure, 300)], $actor);
        $row->delete();
    }

    public function moveRow(ProgrammeRow $row, int $direction, User $actor): void
    {
        $this->assertOpen($row->sheet->engagement);
        DB::transaction(function () use ($row, $direction) {
            $rows = $row->sheet->rows()->lockForUpdate()->get()->values()->all();
            $i = collect($rows)->search(fn ($r) => $r->id === $row->id);
            $j = $i + ($direction < 0 ? -1 : 1);
            if ($j < 0 || $j >= count($rows)) {
                return;
            }
            [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
            foreach ($rows as $k => $r) {
                if ($r->position !== ($k + 1) * 10) {
                    $r->forceFill(['position' => ($k + 1) * 10])->save();
                }
            }
        });
    }

    /** Preparer sign-off: records "Done by" and "Date" and binds them to the row's content. */
    public function signDone(ProgrammeRow $row, User $actor): void
    {
        $this->assertOpen($row->sheet->engagement);
        if ($row->isDone()) {
            throw ValidationException::withMessages(['row' => "{$row->ref} is already signed by ".($row->doneBy?->name ?? 'another user').'.']);
        }
        if (trim($row->procedure) === '') {
            throw ValidationException::withMessages(['row' => 'Describe the procedure before signing it off.']);
        }
        if (blank($row->conclusion)) {
            throw ValidationException::withMessages(['row' => "Record the conclusion for {$row->ref} before signing it off."]);
        }
        $row->forceFill(['done_by' => $actor->id, 'done_at' => now(), 'signed_hash' => $row->contentHash()])->save();
        $this->logger->log('programme.row_done', "Programme step {$row->ref} signed off as done", $row, ['hash' => $row->signed_hash], $actor);
    }

    public function review(ProgrammeRow $row, User $actor): void
    {
        $engagement = $row->sheet->engagement;
        $this->assertOpen($engagement);
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.review_working_papers')) {
            throw ValidationException::withMessages(['row' => 'Only the engagement partner or manager reviews programme steps.']);
        }
        if (! $row->isDone()) {
            throw ValidationException::withMessages(['row' => "{$row->ref} must be signed as done before review."]);
        }
        if ($row->done_by === $actor->id) {
            throw ValidationException::withMessages(['row' => 'The preparer cannot review their own step.']);
        }
        if ($row->isReviewed()) {
            throw ValidationException::withMessages(['row' => "{$row->ref} is already reviewed."]);
        }
        if ($row->signatureBroken()) {
            throw ValidationException::withMessages(['row' => "{$row->ref} changed after it was signed. The preparer must sign again."]);
        }
        $row->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
        $this->logger->log('programme.row_reviewed', "Programme step {$row->ref} reviewed", $row, ['hash' => $row->signed_hash], $actor);
    }

    /** A person may withdraw their own sign-off: the preparer while unreviewed, the reviewer always. */
    public function unsign(ProgrammeRow $row, User $actor): void
    {
        $this->assertOpen($row->sheet->engagement);
        if ($row->isReviewed() && $row->reviewed_by === $actor->id) {
            $row->forceFill(['reviewed_by' => null, 'reviewed_at' => null])->save();
            $this->logger->log('programme.review_withdrawn', "Review of {$row->ref} withdrawn", $row, [], $actor);

            return;
        }
        if ($row->isDone() && ! $row->isReviewed() && $row->done_by === $actor->id) {
            $row->forceFill(['done_by' => null, 'done_at' => null, 'signed_hash' => null])->save();
            $this->logger->log('programme.done_withdrawn', "Sign-off of {$row->ref} withdrawn", $row, [], $actor);

            return;
        }
        throw ValidationException::withMessages(['row' => 'You can only withdraw your own sign-off (and a preparer only before review).']);
    }

    public function addColumn(ProgrammeSheet $sheet, string $label, User $actor): void
    {
        $this->assertOpen($sheet->engagement);
        $label = trim($label);
        $cols = $sheet->customColumns();
        if ($label === '' || mb_strlen($label) > 40) {
            throw ValidationException::withMessages(['label' => 'Name the column (up to 40 characters).']);
        }
        if (count($cols) >= self::MAX_CUSTOM_COLUMNS) {
            throw ValidationException::withMessages(['label' => 'A sheet can have up to '.self::MAX_CUSTOM_COLUMNS.' extra columns.']);
        }
        $taken = collect($cols)->pluck('label')->merge(collect(self::COLUMNS)->pluck(0))->map(fn ($l) => mb_strtolower($l));
        if ($taken->contains(mb_strtolower($label))) {
            throw ValidationException::withMessages(['label' => 'A column with that name already exists.']);
        }
        $cols[] = ['key' => Str::lower(Str::random(8)), 'label' => $label];
        $sheet->update(['columns' => $cols, 'updated_by' => $actor->id]);
        $this->logger->log('programme.column_added', "Column \"{$label}\" added to {$sheet->reference}", $sheet, [], $actor);
    }

    public function renameColumn(ProgrammeSheet $sheet, string $key, string $label, User $actor): void
    {
        $this->assertOpen($sheet->engagement);
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 40) {
            throw ValidationException::withMessages(['label' => 'Name the column (up to 40 characters).']);
        }
        $cols = collect($sheet->customColumns());
        if (! $cols->contains('key', $key)) {
            throw ValidationException::withMessages(['label' => 'Unknown column.']);
        }
        $sheet->update(['columns' => $cols->map(fn ($c) => $c['key'] === $key ? ['key' => $key, 'label' => $label] : $c)->all(), 'updated_by' => $actor->id]);
    }

    public function removeColumn(ProgrammeSheet $sheet, string $key, User $actor): void
    {
        $this->assertOpen($sheet->engagement);
        $cols = collect($sheet->customColumns());
        $col = $cols->firstWhere('key', $key) ?? throw ValidationException::withMessages(['label' => 'Unknown column.']);
        $signedWithValue = $sheet->rows()->whereNotNull('done_by')->get()->filter(fn ($r) => filled($r->extra[$key] ?? null))->count();
        if ($signedWithValue > 0) {
            throw ValidationException::withMessages(['label' => "The column holds values on {$signedWithValue} signed row(s). Clear those cells first."]);
        }
        DB::transaction(function () use ($sheet, $cols, $key, $col, $actor) {
            foreach ($sheet->rows()->get() as $r) {
                if (array_key_exists($key, $r->extra ?? [])) {
                    $extra = $r->extra;
                    unset($extra[$key]);
                    $r->forceFill(['extra' => $extra])->save();
                }
            }
            $sheet->update(['columns' => $cols->reject(fn ($c) => $c['key'] === $key)->values()->all(), 'updated_by' => $actor->id]);
            $this->logger->log('programme.column_removed', "Column \"{$col['label']}\" removed from {$sheet->reference}", $sheet, [], $actor);
        });
    }

    public function updateSheet(ProgrammeSheet $sheet, array $data, User $actor): void
    {
        $this->assertOpen($sheet->engagement);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw ValidationException::withMessages(['title' => 'Give the programme a title (up to 200 characters).']);
        }
        $sheet->update(['title' => $title, 'objective' => filled($data['objective'] ?? null) ? mb_substr((string) $data['objective'], 0, 4000) : null, 'updated_by' => $actor->id]);
    }

    public function deleteSheet(ProgrammeSheet $sheet, User $actor): void
    {
        $this->assertOpen($sheet->engagement);
        if ($sheet->rows()->whereNotNull('done_by')->exists()) {
            throw ValidationException::withMessages(['sheet' => 'This programme has signed steps and cannot be removed.']);
        }
        DB::transaction(function () use ($sheet, $actor) {
            $sheet->rows()->delete();
            $this->logger->log('programme.sheet_deleted', "Programme sheet {$sheet->reference} removed", $sheet->engagement, ['reference' => $sheet->reference, 'title' => $sheet->title], $actor);
            $sheet->delete();
        });
    }

    /** @return list<string> */
    public function headers(ProgrammeSheet $sheet): array
    {
        return [...collect(self::COLUMNS)->pluck(0)->all(), ...collect($sheet->customColumns())->pluck('label')->all(), 'Reviewed by', 'Row ID (do not edit)'];
    }

    public function export(ProgrammeSheet $sheet): string
    {
        $sheet->loadMissing(['rows.doneBy', 'rows.reviewer', 'engagement.client', 'fsli']);
        $custom = $sheet->customColumns();
        $rows = $sheet->rows->map(fn (ProgrammeRow $r) => [
            $r->ref, $r->procedure, $r->assertions, $r->risk,
            $r->doneBy?->name, $r->done_at ? local_time($r->done_at, 'Y-m-d') : null,
            $r->wp_ref, $r->conclusion,
            ...array_map(fn ($c) => $r->extra[$c['key']] ?? null, $custom),
            $r->reviewer ? $r->reviewer->name.' '.local_time($r->reviewed_at, 'Y-m-d') : null,
            $r->uuid,
        ])->all();
        $headers = $this->headers($sheet);
        $widths = [10, 60, 10, 22, 16, 11, 18, 40, ...array_fill(0, count($custom), 20), 22, 38];
        $path = tempnam(sys_get_temp_dir(), 'ps').'.xlsx';
        $e = $sheet->engagement;
        $this->writer->write($path, $sheet->reference, $headers, $rows, $widths, [count($headers) - 1], [
            $e->client->name.' — '.$e->name,
            $sheet->reference.' '.$sheet->title.' ('.$sheet->stage->label().')',
            'Done by, Date and Reviewed by are recorded in AUDIRA when a step is signed off; changes to them in this file are ignored.',
        ]);

        return $path;
    }

    /**
     * Applies an uploaded workbook. Rows are matched on the hidden Row ID; rows without one are
     * added. Rows missing from the file are left as they are. Typed cells only — sign-off columns
     * are ignored. A changed signed row loses its sign-offs, exactly as an edit in the grid would.
     *
     * @return array{updated: int, added: int, unchanged: int, cleared: int, columns_added: int}
     */
    public function import(ProgrammeSheet $sheet, string $path, User $actor): array
    {
        $this->assertOpen($sheet->engagement);
        $raw = $this->reader->read($path, 'xlsx', 3000, 4000, 'the programme');
        $known = ['ref' => 'ref', 'procedure' => 'procedure', 'assertion' => 'assertions', 'risk' => 'risk', 'wp ref' => 'wp_ref', 'conclusion' => 'conclusion', 'row id (do not edit)' => 'uuid'];
        $ignored = ['done by', 'date', 'reviewed by'];
        foreach ($sheet->customColumns() as $c) {
            $known[mb_strtolower($c['label'])] = 'x:'.$c['key'];
        }

        // Unknown headings become new custom columns, so a column added in Excel survives the upload.
        $headerIndex = collect($raw)->search(fn ($r) => in_array('procedure', array_map(fn ($c) => mb_strtolower(trim((string) $c)), $r), true));
        $columnsAdded = 0;
        if ($headerIndex !== false) {
            foreach ($raw[$headerIndex] as $h) {
                $l = mb_strtolower(trim((string) $h));
                if ($l !== '' && ! isset($known[$l]) && ! in_array($l, $ignored, true)) {
                    $this->addColumn($sheet, trim((string) $h), $actor);
                    $sheet->refresh();
                    $c = collect($sheet->customColumns())->last();
                    $known[$l] = 'x:'.$c['key'];
                    $columnsAdded++;
                }
            }
        }
        [$map, $rows] = $this->methodology->locateHeader($raw, $known);

        return DB::transaction(function () use ($sheet, $map, $rows, $actor, $columnsAdded) {
            $existing = $sheet->rows()->get()->keyBy('uuid');
            $out = ['updated' => 0, 'added' => 0, 'unchanged' => 0, 'cleared' => 0, 'columns_added' => $columnsAdded];
            foreach ($rows as $line) {
                $values = [];
                foreach ($map as $field => $col) {
                    $values[$field] = trim((string) ($line[$col] ?? ''));
                }
                $uuid = $values['uuid'] ?? '';
                unset($values['uuid']);
                if (implode('', $values) === '') {
                    continue;
                }
                $row = $uuid !== '' ? $existing->get($uuid) : null;
                if (! $row) {
                    $row = $this->addRow($sheet, null, $actor);
                    $out['added']++;
                    $new = true;
                } else {
                    $new = false;
                }
                $wasDone = $row->isDone();
                $changed = false;
                foreach ($values as $field => $v) {
                    $before = $row->contentHash();
                    $this->updateCell($row, $field, $v, $actor);
                    $changed = $changed || $before !== $row->contentHash();
                }
                if (! $new) {
                    $changed ? $out['updated']++ : $out['unchanged']++;
                    if ($wasDone && ! $row->isDone()) {
                        $out['cleared']++;
                    }
                }
            }
            $this->logger->log('programme.imported', "Programme {$sheet->reference} updated from Excel", $sheet, $out, $actor);

            return $out;
        });
    }

    /** Engagement-wide sign-off status, for the completion gate. */
    public function status(Engagement $engagement): array
    {
        $rows = ProgrammeRow::whereIn('sheet_id', $engagement->programmeSheets()->select('id'));

        return [
            'rows' => (clone $rows)->count(),
            'not_done' => (clone $rows)->whereNull('done_by')->count(),
            'not_reviewed' => (clone $rows)->whereNotNull('done_by')->whereNull('reviewed_by')->count(),
        ];
    }

    /** @return Collection<string, Collection<int, ProgrammeSheet>> grouped by stage value */
    public function grouped(Engagement $engagement): Collection
    {
        return $engagement->programmeSheets()->with('fsli')
            ->withCount(['rows', 'rows as done_count' => fn ($q) => $q->whereNotNull('done_by'), 'rows as reviewed_count' => fn ($q) => $q->whereNotNull('reviewed_by')])
            ->get()->groupBy(fn ($s) => $s->stage->value);
    }

    private function label(ProgrammeSheet $sheet, string $field): string
    {
        if (str_starts_with($field, 'x:')) {
            return collect($sheet->customColumns())->firstWhere('key', substr($field, 2))['label'] ?? 'a custom column';
        }

        return self::COLUMNS[$field][0] ?? $field;
    }

    private function nextRef(ProgrammeSheet $sheet, Collection $rows): string
    {
        $max = $rows->map(fn ($r) => preg_match('/(\d+)$/', (string) $r->ref, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return $sheet->reference.'.'.sprintf('%02d', $max + 1);
    }

    private function uniqueReference(Engagement $engagement, string $base): string
    {
        $base = mb_substr($base, 0, 16);
        $ref = $base;
        for ($i = 2; $engagement->programmeSheets()->where('reference', $ref)->exists(); $i++) {
            $ref = $base.$i;
        }

        return $ref;
    }

    private function sortOrder(ProgrammeStage $stage, ?EngagementFsli $fsli, ?MethodologyProgramme $p): int
    {
        $stageBase = ['planning' => 0, 'controls' => 100_000, 'substantive' => 200_000][$stage->value];

        return $stageBase + ($fsli ? $fsli->sort_order * 10 : 0) + ($p?->sort_order ?? 0);
    }

    public function assertOpen(Engagement $engagement): void
    {
        if ($engagement->status->isLocked()) {
            throw ValidationException::withMessages(['engagement' => 'This engagement is locked.']);
        }
    }
}
