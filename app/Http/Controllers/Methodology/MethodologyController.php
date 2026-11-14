<?php

namespace App\Http\Controllers\Methodology;

use App\Enums\FsliClassification;
use App\Enums\ProgrammeStage;
use App\Http\Controllers\Controller;
use App\Models\MethodologyLibrary;
use App\Models\MethodologyProgramme;
use App\Models\MethodologyStep;
use App\Services\Methodology\MethodologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Firm methodology libraries: browse AUDIRA's template, copy it and maintain the firm's copies. */
class MethodologyController extends Controller
{
    public function __construct(private MethodologyService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('methodology.view');

        return view('methodology.index', [
            'libraries' => MethodologyLibrary::withCount('programmes')->with('basedOn')->orderByRaw('firm_id IS NOT NULL')->orderBy('status')->orderBy('name')->get(),
            'canManage' => Gate::allows('methodology.manage'),
        ]);
    }

    public function show(Request $request, MethodologyLibrary $methodologyLibrary): View
    {
        Gate::authorize('methodology.view');

        return view('methodology.show', [
            'library' => $methodologyLibrary,
            'programmes' => $methodologyLibrary->programmes()->withCount('steps')->get()->groupBy(fn ($p) => $p->stage->value),
            'canEdit' => Gate::allows('methodology.manage') && $methodologyLibrary->isEditableBy($request->user()),
            'canManage' => Gate::allows('methodology.manage'),
            'stages' => ProgrammeStage::options(),
            'classes' => FsliClassification::options(),
            'inUse' => $methodologyLibrary->isPlatform() ? null : \App\Models\Engagement::where('methodology_library_id', $methodologyLibrary->id)->count(),
        ]);
    }

    public function copy(Request $request, MethodologyLibrary $methodologyLibrary): RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $copy = $this->service->copy($methodologyLibrary, $data['name'], $request->user());

        return redirect()->route('methodology.show', $copy)->with('status', "Firm library \"{$copy->name}\" created. Edit any programme to make it your own.");
    }

    public function update(Request $request, MethodologyLibrary $methodologyLibrary): RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $this->service->update($methodologyLibrary, $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000']]), $request->user());

        return back()->with('status', 'Library details saved.');
    }

    public function archive(Request $request, MethodologyLibrary $methodologyLibrary): RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $this->service->archive($methodologyLibrary, $request->user());

        return redirect()->route('methodology.index')->with('status', "\"{$methodologyLibrary->name}\" archived. Engagements already using it keep their programmes.");
    }

    public function storeProgramme(Request $request, MethodologyLibrary $methodologyLibrary): RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $p = $this->service->saveProgramme($methodologyLibrary, null, $this->programmeInput($request), $request->user());

        return redirect()->route('methodology.programmes.show', [$methodologyLibrary, $p])->with('status', "Programme {$p->ref_prefix} added. Add its steps below.");
    }

    public function programme(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme): View
    {
        Gate::authorize('methodology.view');

        return view('methodology.programme', [
            'library' => $methodologyLibrary,
            'programme' => $methodologyProgramme->load('steps'),
            'canEdit' => Gate::allows('methodology.manage') && $methodologyLibrary->isEditableBy($request->user()),
            'stages' => ProgrammeStage::options(),
            'classes' => FsliClassification::options(),
        ]);
    }

    public function updateProgramme(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme): RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $this->service->saveProgramme($methodologyLibrary, $methodologyProgramme, $this->programmeInput($request), $request->user());

        return back()->with('status', 'Programme saved.');
    }

    public function destroyProgramme(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme): RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $this->service->deleteProgramme($methodologyProgramme, $request->user());

        return redirect()->route('methodology.show', $methodologyLibrary)->with('status', "Programme {$methodologyProgramme->ref_prefix} removed from the library.");
    }

    public function export(MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme): BinaryFileResponse
    {
        Gate::authorize('methodology.view');
        $path = $this->service->export($methodologyProgramme->load('steps', 'library'));

        return response()->download($path, Str::slug($methodologyLibrary->name.' '.$methodologyProgramme->ref_prefix).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function import(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme): RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $file = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:xlsx']])['file'];
        $r = $this->service->import($methodologyProgramme, $file->getRealPath(), $request->user());

        return back()->with('status', "Excel applied: {$r['updated']} step(s) updated, {$r['added']} added, {$r['unchanged']} unchanged.");
    }

    public function storeStep(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme): JsonResponse|RedirectResponse
    {
        Gate::authorize('methodology.manage');
        $after = $request->filled('after') ? $methodologyProgramme->steps()->where('uuid', $request->input('after'))->firstOrFail() : null;
        $step = $this->service->addStep($methodologyProgramme, $after, $request->user());

        return $request->wantsJson() ? response()->json(['step' => $this->stepJson($step), 'order' => $methodologyProgramme->steps()->pluck('uuid')]) : back();
    }

    public function updateStep(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme, MethodologyStep $methodologyStep): JsonResponse
    {
        Gate::authorize('methodology.manage');
        $data = $request->validate(['field' => ['required', 'string', 'max:20'], 'value' => ['nullable', 'string', 'max:4000']]);
        $step = $this->service->updateStep($methodologyStep, $data['field'], $data['value'] ?? null, $request->user());

        return response()->json(['step' => $this->stepJson($step)]);
    }

    public function destroyStep(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme, MethodologyStep $methodologyStep): JsonResponse
    {
        Gate::authorize('methodology.manage');
        $this->service->deleteStep($methodologyStep, $request->user());

        return response()->json(['deleted' => $methodologyStep->uuid]);
    }

    public function moveStep(Request $request, MethodologyLibrary $methodologyLibrary, MethodologyProgramme $methodologyProgramme, MethodologyStep $methodologyStep): JsonResponse
    {
        Gate::authorize('methodology.manage');
        $this->service->moveStep($methodologyStep, (int) $request->validate(['direction' => ['required', 'in:-1,1']])['direction'], $request->user());

        return response()->json(['order' => $methodologyProgramme->steps()->pluck('uuid')]);
    }

    private function programmeInput(Request $request): array
    {
        return $request->validate([
            'stage' => ['required', 'string'], 'scope' => ['required', 'string'],
            'fsli_codes' => ['nullable', 'string', 'max:200'], 'fsli_classification' => ['nullable', 'string'],
            'ref_prefix' => ['required', 'string', 'max:12'], 'title' => ['required', 'string', 'max:200'],
            'objective' => ['nullable', 'string', 'max:4000'],
        ]);
    }

    public static function stepJson(MethodologyStep $s): array
    {
        return ['uuid' => $s->uuid, 'ref' => $s->ref, 'procedure' => $s->procedure, 'assertions' => $s->assertions, 'isa_refs' => $s->isa_refs, 'guidance' => $s->guidance];
    }
}
