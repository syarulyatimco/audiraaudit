<?php

namespace App\Http\Controllers\WorkingPapers;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Services\WorkingPapers\EntityUnderstandingService;
use App\Services\WorkingPapers\WorkingPaperService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EntityUnderstandingController extends Controller
{
    public function show(Request $request, Engagement $engagement, WorkingPaperService $papers): View
    {
        Gate::authorize('view', $engagement);
        $perms = WorkingPaperController::permissionsFor($request, $engagement);

        // The B1 paper is created lazily by the first person able to edit; viewers just see empty sections.
        $wp = $engagement->workingPapers()->where('system_key', 'entity_understanding')->first();
        if (! $wp && $perms['canEdit']) {
            $wp = $papers->entityUnderstandingPaper($engagement, $request->user());
        }
        $wp?->load(['signoffs.user', 'reviewNotes.raiser', 'reviewNotes.responder', 'reviewNotes.clearer', 'documents.currentVersion', 'citations.paragraph.version.standard']);

        return view('working-papers.entity-understanding', [
            'engagement' => $engagement->load(['client', 'members']),
            'wp' => $wp,
            'sections' => config('audira.entity_understanding'),
            'content' => $engagement->understandingSections()->with('editor')->get()->keyBy('section_key'),
            'linkable' => $wp ? $engagement->documents()->active()->whereNotIn('id', $wp->documents->pluck('id'))->orderBy('title')->get() : collect(),
            ...$perms,
        ]);
    }

    public function update(Request $request, Engagement $engagement, EntityUnderstandingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $keys = array_keys(config('audira.entity_understanding'));
        $data = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*' => ['nullable', 'string', 'max:20000'],
        ]);
        $service->save($engagement, array_intersect_key($data['sections'], array_flip($keys)), $request->user());

        return back()->with('status', 'Entity understanding saved.');
    }
}
