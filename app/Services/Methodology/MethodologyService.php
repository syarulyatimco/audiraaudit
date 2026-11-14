<?php

namespace App\Services\Methodology;

use App\Enums\FsliClassification;
use App\Enums\ProgrammeStage;
use App\Models\MethodologyLibrary;
use App\Models\MethodologyProgramme;
use App\Models\MethodologyStep;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Spreadsheets\XlsxWriter;
use App\Services\TrialBalance\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Firm methodology libraries. AUDIRA's template is read-only; a firm copies it (or one of its own
 * libraries) and edits the copy. Engagements take a snapshot of a library when it is applied, so
 * later edits never change an engagement that is already under way.
 */
class MethodologyService
{
    public const STEP_FIELDS = ['ref' => 20, 'procedure' => 4000, 'assertions' => 60, 'isa_refs' => 160, 'guidance' => 4000];

    public const XLSX_HEADERS = ['Ref', 'Procedure', 'Assertion', 'ISA reference', 'Guidance', 'Row ID (do not edit)'];

    public function __construct(private ActivityLogger $logger, private XlsxWriter $writer, private SpreadsheetReader $reader) {}

    public function copy(MethodologyLibrary $source, string $name, User $actor): MethodologyLibrary
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Name the firm library (up to 160 characters).']);
        }

        return DB::transaction(function () use ($source, $name, $actor) {
            $copy = MethodologyLibrary::create([
                'name' => $name,
                'description' => $source->description,
                'based_on_id' => $source->id,
                'version' => 1,
                'status' => 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            foreach ($source->programmes()->with('steps')->get() as $p) {
                $np = $copy->programmes()->create($p->only(['stage', 'scope', 'fsli_codes', 'fsli_classification', 'ref_prefix', 'title', 'objective', 'sort_order']));
                foreach ($p->steps as $s) {
                    $np->steps()->create($s->only(['position', 'ref', 'procedure', 'assertions', 'isa_refs', 'guidance']));
                }
            }
            $this->logger->log('methodology.copied', "Methodology library \"{$copy->name}\" created from \"{$source->name}\"", $copy, ['source' => $source->uuid], $actor);

            return $copy;
        });
    }

    public function update(MethodologyLibrary $library, array $data, User $actor): void
    {
        $this->assertEditable($library, $actor);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'The library needs a name.']);
        }
        $library->update(['name' => mb_substr($name, 0, 160), 'description' => $data['description'] ?? null, 'updated_by' => $actor->id]);
        $this->touch($library, $actor);
    }

    public function archive(MethodologyLibrary $library, User $actor): void
    {
        $this->assertEditable($library, $actor);
        $library->update(['status' => 'archived', 'updated_by' => $actor->id]);
        $this->logger->log('methodology.archived', "Methodology library \"{$library->name}\" archived", $library, [], $actor);
    }

    public function saveProgramme(MethodologyLibrary $library, ?MethodologyProgramme $programme, array $data, User $actor): MethodologyProgramme
    {
        $this->assertEditable($library, $actor);
        $stage = ProgrammeStage::tryFrom((string) ($data['stage'] ?? '')) ?? throw ValidationException::withMessages(['stage' => 'Choose the stage.']);
        $scope = (string) ($data['scope'] ?? '');
        $codes = collect(preg_split('/[\s,;]+/', strtoupper((string) ($data['fsli_codes'] ?? ''))))->filter()->unique()->values()->all();
        $class = FsliClassification::tryFrom((string) ($data['fsli_classification'] ?? ''));
        $prefix = strtoupper(trim((string) ($data['ref_prefix'] ?? '')));
        $title = trim((string) ($data['title'] ?? ''));

        $errors = [];
        if (! in_array($scope, ['engagement', 'fsli', 'classification'], true)) {
            $errors['scope'] = 'Choose where the programme is routed.';
        } elseif ($scope === 'fsli' && $codes === []) {
            $errors['fsli_codes'] = 'List at least one FSLI code, e.g. TR or REV, TR.';
        } elseif ($scope === 'classification' && ! $class) {
            $errors['fsli_classification'] = 'Choose the FSLI classification.';
        }
        if (! preg_match('/^[A-Z0-9][A-Z0-9\-]{0,11}$/', $prefix)) {
            $errors['ref_prefix'] = 'Use 1–12 letters, digits or hyphens.';
        } elseif ($library->programmes()->where('ref_prefix', $prefix)->when($programme, fn ($q) => $q->whereKeyNot($programme->id))->exists()) {
            $errors['ref_prefix'] = 'Another programme in this library uses that reference.';
        }
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Give the programme a title (up to 200 characters).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $attrs = [
            'stage' => $stage, 'scope' => $scope,
            'fsli_codes' => $scope === 'fsli' ? $codes : [],
            'fsli_classification' => $scope === 'classification' ? $class : null,
            'ref_prefix' => $prefix, 'title' => $title,
            'objective' => filled($data['objective'] ?? null) ? mb_substr((string) $data['objective'], 0, 4000) : null,
        ];
        if ($programme) {
            $programme->update($attrs);
        } else {
            $programme = $library->programmes()->create([...$attrs, 'sort_order' => (int) $library->programmes()->max('sort_order') + 10]);
        }
        $this->touch($library, $actor);

        return $programme;
    }

    public function deleteProgramme(MethodologyProgramme $programme, User $actor): void
    {
        $this->assertEditable($programme->library, $actor);
        $programme->delete();
        $this->touch($programme->library, $actor);
    }

    public function addStep(MethodologyProgramme $programme, ?MethodologyStep $after, User $actor): MethodologyStep
    {
        $this->assertEditable($programme->library, $actor);

        return DB::transaction(function () use ($programme, $after) {
            $steps = $programme->steps()->get();
            $at = $after ? $steps->search(fn ($s) => $s->id === $after->id) + 1 : $steps->count();
            $step = new MethodologyStep(['ref' => sprintf('%02d', $steps->count() + 1), 'procedure' => '']);
            $step->programme_id = $programme->id;
            $ordered = $steps->all();
            array_splice($ordered, $at, 0, [$step]);
            foreach ($ordered as $i => $s) {
                $s->position = ($i + 1) * 10;
                $s->save();
            }

            return $step;
        });
    }

    public function updateStep(MethodologyStep $step, string $field, ?string $value, User $actor): MethodologyStep
    {
        $this->assertEditable($step->programme->library, $actor);
        if (! array_key_exists($field, self::STEP_FIELDS)) {
            throw ValidationException::withMessages(['field' => 'That column cannot be edited.']);
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > self::STEP_FIELDS[$field]) {
            throw ValidationException::withMessages(['value' => 'The text is too long for this column.']);
        }
        $step->update([$field => $value === '' && $field !== 'procedure' ? null : $value]);

        return $step;
    }

    public function deleteStep(MethodologyStep $step, User $actor): void
    {
        $this->assertEditable($step->programme->library, $actor);
        $step->delete();
    }

    public function moveStep(MethodologyStep $step, int $direction, User $actor): void
    {
        $this->assertEditable($step->programme->library, $actor);
        DB::transaction(function () use ($step, $direction) {
            $steps = $step->programme->steps()->get()->values()->all();
            $i = collect($steps)->search(fn ($s) => $s->id === $step->id);
            $j = $i + ($direction < 0 ? -1 : 1);
            if ($j < 0 || $j >= count($steps)) {
                return;
            }
            [$steps[$i], $steps[$j]] = [$steps[$j], $steps[$i]];
            foreach ($steps as $k => $s) {
                $s->update(['position' => ($k + 1) * 10]);
            }
        });
    }

    /** Writes the programme to an .xlsx file and returns its path. */
    public function export(MethodologyProgramme $programme): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mp').'.xlsx';
        $rows = $programme->steps->map(fn ($s) => [$s->ref, $s->procedure, $s->assertions, $s->isa_refs, $s->guidance, $s->uuid])->all();
        $this->writer->write($path, $programme->ref_prefix, self::XLSX_HEADERS, $rows, [8, 70, 12, 18, 50, 38], [5], [
            $programme->library->name.' — '.$programme->ref_prefix.' '.$programme->title,
            $programme->stage->label().' · '.$programme->routing(),
        ]);

        return $path;
    }

    /** @return array{updated: int, added: int, unchanged: int} */
    public function import(MethodologyProgramme $programme, string $path, User $actor): array
    {
        $this->assertEditable($programme->library, $actor);
        [$map, $rows] = $this->locateHeader($this->reader->read($path, 'xlsx', 2000, 4000, 'the programme'), ['ref' => 'ref', 'procedure' => 'procedure', 'assertion' => 'assertions', 'isa reference' => 'isa_refs', 'guidance' => 'guidance', 'row id (do not edit)' => 'uuid']);

        return DB::transaction(function () use ($programme, $map, $rows, $actor) {
            $existing = $programme->steps()->get()->keyBy('uuid');
            $out = ['updated' => 0, 'added' => 0, 'unchanged' => 0];
            $position = (int) $programme->steps()->max('position');
            foreach ($rows as $line) {
                $cell = fn (string $f) => isset($map[$f]) ? trim((string) ($line[$map[$f]] ?? '')) : null;
                if (($cell('procedure') ?? '') === '' && ($cell('ref') ?? '') === '') {
                    continue;
                }
                $values = [];
                foreach (array_keys(self::STEP_FIELDS) as $f) {
                    if (($v = $cell($f)) !== null) {
                        $values[$f] = mb_substr($v, 0, self::STEP_FIELDS[$f]);
                    }
                }
                $step = ($uuid = $cell('uuid')) ? $existing->get($uuid) : null;
                if ($step) {
                    $step->fill(array_map(fn ($v) => $v === '' ? null : $v, $values));
                    $step->procedure ??= '';
                    if ($step->isDirty()) {
                        $step->save();
                        $out['updated']++;
                    } else {
                        $out['unchanged']++;
                    }
                } else {
                    $programme->steps()->create([...$values, 'position' => $position += 10, 'ref' => $values['ref'] ?? '', 'procedure' => $values['procedure'] ?? '']);
                    $out['added']++;
                }
            }
            $this->touch($programme->library, $actor);
            $this->logger->log('methodology.imported', "Programme {$programme->ref_prefix} updated from Excel", $programme->library, $out, $actor);

            return $out;
        });
    }

    /**
     * Finds the header row (the first row containing "Procedure") and maps known headings.
     *
     * @param  array<string, string>  $known  lower-case heading => field
     * @return array{0: array<string, int>, 1: list<list<string>>}
     */
    public function locateHeader(array $rows, array $known): array
    {
        foreach ($rows as $i => $row) {
            $lower = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $row);
            if (in_array('procedure', $lower, true)) {
                $map = [];
                foreach ($lower as $col => $h) {
                    if (isset($known[$h])) {
                        $map[$known[$h]] = $col;
                    }
                }

                return [$map, array_slice($rows, $i + 1)];
            }
        }
        throw ValidationException::withMessages(['file' => 'The worksheet needs a header row with a "Procedure" column. Download the template to start.']);
    }

    public function assertEditable(MethodologyLibrary $library, User $actor): void
    {
        if (! $library->isEditableBy($actor)) {
            throw ValidationException::withMessages(['library' => $library->isPlatform()
                ? 'The AUDIRA methodology is read-only. Make a firm copy to customise it.'
                : 'This library is archived and can no longer be edited.']);
        }
    }

    private function touch(MethodologyLibrary $library, User $actor): void
    {
        $library->forceFill(['updated_by' => $actor->id, 'updated_at' => now()])->save();
    }
}
