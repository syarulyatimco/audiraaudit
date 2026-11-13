<?php

namespace App\Http\Controllers\WorkingPapers;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\ReviewNote;
use App\Models\WorkingPaper;
use App\Services\WorkingPapers\WorkingPaperService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReviewNoteController extends Controller
{
    public function __construct(private WorkingPaperService $service) {}

    public function store(Request $request, Engagement $engagement, WorkingPaper $workingPaper): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $this->service->raiseNote($workingPaper, $data['body'], $request->user());

        return back()->with('status', 'Review note raised.');
    }

    public function respond(Request $request, Engagement $engagement, WorkingPaper $workingPaper, ReviewNote $reviewNote): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['response' => ['required', 'string', 'max:5000']]);
        $this->service->respondToNote($reviewNote, $data['response'], $request->user());

        return back()->with('status', 'Response recorded.');
    }

    public function clear(Request $request, Engagement $engagement, WorkingPaper $workingPaper, ReviewNote $reviewNote): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $this->service->clearNote($reviewNote, $request->user());

        return back()->with('status', 'Review note cleared.');
    }
}
