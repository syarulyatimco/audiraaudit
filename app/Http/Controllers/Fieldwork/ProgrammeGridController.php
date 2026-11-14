<?php

namespace App\Http\Controllers\Fieldwork;

use App\Enums\ProgrammeStage;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\MethodologyLibrary;
use App\Models\ProgrammeRow;
use App\Models\ProgrammeSheet;
use App\Services\Methodology\LinkTokens;
use App\Services\Methodology\ProgrammeGridService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Engagement programme grids. Structure (library choice, sheets, rows, columns, Excel upload) needs
 * engagement.manage_programme; typing in cells and signing "done" needs engagement.execute_procedures;
 * review needs engagement.review_working_papers.
 */
class ProgrammeGridController extends Controller
{
    public function __construct(private ProgrammeGridService $grid, private LinkTokens $links) {}

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $sheets = $this->grid->grouped($engagement);

        return view('fieldwork.programmes', [
            'engagement' => $engagement->load(['client', 'methodologyLibrary']),
            'sheets' => $sheets,
            'stages' => ProgrammeStage::cases(),
            'libraries' => MethodologyLibrary::active()->orderByRaw('firm_id IS NOT NULL')->orderBy('name')->get(),
            'fslis' => $engagement->fslis()->orderBy('sort_order')->get(),
            'mappedCount' => $engagement->fslis()->whereHas('mappings')->count(),
            'status' => $this->grid->status($engagement),
            'canManage' => $this->can($request, $engagement, 'engagement.manage_programme'),
            'hasSigned' => ($this->grid->status($engagement)['rows'] - $this->grid->status($engagement)['not_done']) > 0,
        ]);
    }

    public function apply(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $uuid = $request->validate(['library' => ['required', 'uuid']])['library'];
        $library = MethodologyLibrary::where('uuid', $uuid)->firstOrFail();
        $r = $this->grid->apply($engagement, $library, $request->user());

        return redirect()->route('programmes.index', $engagement)->with('status', "\"{$library->name}\" applied: {$r['created']} programme sheet(s) created.".$this->unroutedNote($r));
    }

    public function refresh(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $r = $this->grid->refresh($engagement, $request->user());

        return back()->with('status', ($r['created'] ? "{$r['created']} new programme sheet(s) added for newly mapped FSLIs." : 'Every mapped FSLI already has its programmes.').$this->unroutedNote($r));
    }

    public function store(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $sheet = $this->grid->addSheet($engagement, $request->validate([
            'stage' => ['required', 'string'], 'engagement_fsli_id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:200'],
        ]), $request->user());

        return redirect()->route('programmes.show', [$engagement, $sheet])->with('status', "Programme {$sheet->reference} added.");
    }

    public function show(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet): View
    {
        Gate::authorize('view', $engagement);
        $sheet = $programmeSheet->load(['rows.doneBy', 'rows.reviewer', 'fsli', 'sourceProgramme.library']);
        $resolved = $this->links->resolve($this->links->find(...$sheet->rows->flatMap(fn ($r) => [$r->wp_ref, $r->conclusion])->all()));
        $locked = $engagement->status->isLocked();
        $siblings = $engagement->programmeSheets()->get(['id', 'uuid', 'reference', 'title', 'stage']);

        return view('fieldwork.programme-sheet', [
            'engagement' => $engagement->load('client'),
            'sheet' => $sheet,
            'rows' => $sheet->rows->map(fn ($r) => $this->rowJson($r, $engagement, $sheet, $resolved))->values(),
            'columns' => collect(ProgrammeGridService::COLUMNS)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0]])->values(),
            'custom' => $sheet->customColumns(),
            'risks' => $engagement->risks()->get(['reference', 'title'])->map(fn ($r) => $r->reference.' '.$r->title),
            'documents' => $engagement->documents()->active()->orderBy('title')->get(['uuid', 'title']),
            'siblings' => $siblings,
            'canManage' => ! $locked && $this->can($request, $engagement, 'engagement.manage_programme'),
            'canEdit' => ! $locked && $this->can($request, $engagement, 'engagement.execute_procedures'),
            'canReview' => ! $locked && $this->can($request, $engagement, 'engagement.review_working_papers'),
            'userId' => $request->user()->id,
        ]);
    }

    public function update(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet): RedirectResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $this->grid->updateSheet($programmeSheet, $request->validate(['title' => ['required', 'string', 'max:200'], 'objective' => ['nullable', 'string', 'max:4000']]), $request->user());

        return back()->with('status', 'Programme details saved.');
    }

    public function destroy(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet): RedirectResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $this->grid->deleteSheet($programmeSheet, $request->user());

        return redirect()->route('programmes.index', $engagement)->with('status', "Programme {$programmeSheet->reference} removed.");
    }

    public function export(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet): BinaryFileResponse
    {
        Gate::authorize('view', $engagement);
        $path = $this->grid->export($programmeSheet);

        return response()->download($path, Str::slug($engagement->code.' '.$programmeSheet->reference.' '.$programmeSheet->title).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function import(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet): RedirectResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $file = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:xlsx']])['file'];
        $r = $this->grid->import($programmeSheet, $file->getRealPath(), $request->user());
        $msg = "Excel applied: {$r['updated']} row(s) updated, {$r['added']} added, {$r['unchanged']} unchanged.";
        if ($r['columns_added']) {
            $msg .= " {$r['columns_added']} new column(s) added.";
        }
        if ($r['cleared']) {
            $msg .= " Sign-offs were cleared on {$r['cleared']} changed row(s).";
        }

        return back()->with('status', $msg);
    }

    public function addColumn(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet): JsonResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $this->grid->addColumn($programmeSheet, (string) $request->validate(['label' => ['required', 'string', 'max:40']])['label'], $request->user());

        return response()->json(['custom' => $programmeSheet->fresh()->customColumns()]);
    }

    public function renameColumn(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet, string $key): JsonResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $this->grid->renameColumn($programmeSheet, $key, (string) $request->validate(['label' => ['required', 'string', 'max:40']])['label'], $request->user());

        return response()->json(['custom' => $programmeSheet->fresh()->customColumns()]);
    }

    public function removeColumn(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet, string $key): JsonResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $this->grid->removeColumn($programmeSheet, $key, $request->user());

        return response()->json(['custom' => $programmeSheet->fresh()->customColumns()]);
    }

    public function addRow(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet): JsonResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $after = $request->filled('after') ? $programmeSheet->rows()->where('uuid', $request->input('after'))->firstOrFail() : null;
        $row = $this->grid->addRow($programmeSheet, $after, $request->user());

        return response()->json(['row' => $this->rowJson($row->fresh(), $engagement, $programmeSheet), 'order' => $programmeSheet->rows()->pluck('uuid')]);
    }

    public function updateCell(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet, ProgrammeRow $programmeRow): JsonResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.execute_procedures');
        $data = $request->validate(['field' => ['required', 'string', 'max:20'], 'value' => ['nullable', 'string', 'max:4000']]);
        $row = $this->grid->updateCell($programmeRow, $data['field'], $data['value'] ?? null, $request->user());

        return response()->json(['row' => $this->rowJson($row->fresh(['doneBy', 'reviewer']), $engagement, $programmeSheet)]);
    }

    public function deleteRow(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet, ProgrammeRow $programmeRow): JsonResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $this->grid->deleteRow($programmeRow, $request->user());

        return response()->json(['deleted' => $programmeRow->uuid]);
    }

    public function moveRow(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet, ProgrammeRow $programmeRow): JsonResponse
    {
        $this->authorizeOn($request, $engagement, 'engagement.manage_programme');
        $this->grid->moveRow($programmeRow, (int) $request->validate(['direction' => ['required', 'in:-1,1']])['direction'], $request->user());

        return response()->json(['order' => $programmeSheet->rows()->pluck('uuid')]);
    }

    public function signoff(Request $request, Engagement $engagement, ProgrammeSheet $programmeSheet, ProgrammeRow $programmeRow, string $action): JsonResponse
    {
        $this->authorizeOn($request, $engagement, $action === 'review' ? 'engagement.review_working_papers' : 'engagement.execute_procedures');
        match ($action) {
            'done' => $this->grid->signDone($programmeRow, $request->user()),
            'review' => $this->grid->review($programmeRow, $request->user()),
            'unsign' => $this->grid->unsign($programmeRow, $request->user()),
        };

        return response()->json(['row' => $this->rowJson($programmeRow->fresh(['doneBy', 'reviewer']), $engagement, $programmeSheet)]);
    }

    private function rowJson(ProgrammeRow $r, Engagement $engagement, ProgrammeSheet $sheet, ?\Illuminate\Support\Collection $resolved = null): array
    {
        $resolved ??= $this->links->resolve($this->links->find($r->wp_ref, $r->conclusion));

        return [
            'uuid' => $r->uuid,
            'ref' => $r->ref,
            'procedure' => $r->procedure,
            'assertions' => $r->assertions,
            'risk' => $r->risk,
            'wp_ref' => $r->wp_ref,
            'conclusion' => $r->conclusion,
            'extra' => (object) ($r->extra ?? []),
            'isa_refs' => $r->isa_refs,
            'guidance' => $r->guidance,
            'done_by' => $r->doneBy?->name,
            'done_by_id' => $r->done_by,
            'done_at' => $r->done_at ? local_time($r->done_at, 'j M Y') : null,
            'reviewed_by' => $r->reviewer?->name,
            'reviewed_by_id' => $r->reviewed_by,
            'reviewed_at' => $r->reviewed_at ? local_time($r->reviewed_at, 'j M Y') : null,
            'wp_ref_html' => (string) $this->links->render($r->wp_ref, $resolved),
            'conclusion_html' => (string) $this->links->render($r->conclusion, $resolved),
            'token' => $r->linkToken(),
            'url' => route('links.show', ['step', $r->uuid]),
            'base' => route('programmes.rows.update', [$engagement, $sheet, $r]),
        ];
    }

    private function unroutedNote(array $r): string
    {
        return $r['unrouted'] ? ' No substantive programme in the library for: '.implode(', ', $r['unrouted']).' — add one with "New programme".' : '';
    }

    private function can(Request $request, Engagement $engagement, string $permission): bool
    {
        return Permissions::allowsOnEngagement($request->user(), $engagement, $permission);
    }

    private function authorizeOn(Request $request, Engagement $engagement, string $permission): void
    {
        abort_unless($this->can($request, $engagement, $permission), 403);
    }
}
