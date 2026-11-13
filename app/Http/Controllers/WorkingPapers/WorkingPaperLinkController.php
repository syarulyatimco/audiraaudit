<?php

namespace App\Http\Controllers\WorkingPapers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\LibraryParagraph;
use App\Models\WorkingPaper;
use App\Models\WorkingPaperCitation;
use App\Services\WorkingPapers\WorkingPaperService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkingPaperLinkController extends Controller
{
    public function __construct(private WorkingPaperService $service) {}

    public function linkDocument(Request $request, Engagement $engagement, WorkingPaper $workingPaper): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['document_id' => ['required', 'integer']]);
        $document = $engagement->documents()->findOrFail($data['document_id']);
        $this->service->linkDocument($workingPaper, $document, $request->user());

        return back()->with('status', 'Evidence linked.');
    }

    public function unlinkDocument(Request $request, Engagement $engagement, WorkingPaper $workingPaper, Document $document): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $this->service->unlinkDocument($workingPaper, $document, $request->user());

        return back()->with('status', 'Evidence unlinked.');
    }

    public function addCitation(Request $request, Engagement $engagement, WorkingPaper $workingPaper): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate([
            'library_paragraph_id' => ['required', 'integer', 'exists:library_paragraphs,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $paragraph = LibraryParagraph::with('version.standard')->findOrFail($data['library_paragraph_id']);
        $this->service->addCitation($workingPaper, $paragraph, $data['note'] ?? null, $request->user());

        return back()->with('status', 'Citation added: '.$paragraph->citation());
    }

    public function removeCitation(Request $request, Engagement $engagement, WorkingPaper $workingPaper, WorkingPaperCitation $citation): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $this->service->removeCitation($citation, $request->user());

        return back()->with('status', 'Citation removed.');
    }
}
