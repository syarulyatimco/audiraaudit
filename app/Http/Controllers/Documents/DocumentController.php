<?php

namespace App\Http\Controllers\Documents;

use App\Enums\DocumentCategory;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Engagement;
use App\Services\Documents\DocumentService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $service) {}

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $filters = $request->validate([
            'category' => ['nullable', Rule::enum(DocumentCategory::class)],
            'archived' => ['nullable', 'boolean'],
        ]);

        $documents = $engagement->documents()
            ->with(['currentVersion', 'uploader'])->withCount(['versions', 'workingPapers'])
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(! empty($filters['archived']), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->latest()->get();

        return view('documents.index', [
            'engagement' => $engagement->load('client'),
            'documents' => $documents,
            'filters' => $filters,
            'canUpload' => $this->canUpload($request, $engagement),
        ]);
    }

    public function store(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->canUpload($request, $engagement), 403);

        $data = $request->validate([
            'file' => $this->fileRules(),
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $file = $request->file('file');
        $title = ($data['title'] ?? null) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $document = $this->service->upload($engagement, $file, $title, DocumentCategory::from($data['category']), $data['description'] ?? null, $request->user());

        return redirect()->route('documents.show', [$engagement, $document])->with('status', 'Uploaded. The file becomes available once the malware scan passes.');
    }

    public function show(Request $request, Engagement $engagement, Document $document): View
    {
        Gate::authorize('view', $engagement);
        $document->load(['versions.uploader', 'workingPapers', 'uploader']);

        return view('documents.show', [
            'engagement' => $engagement->load('client'),
            'document' => $document,
            'canUpload' => $this->canUpload($request, $engagement) && ! $document->archived_at,
            'canArchive' => ! $engagement->status->isLocked() && ! $document->archived_at && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.archive_documents'),
        ]);
    }

    public function addVersion(Request $request, Engagement $engagement, Document $document): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->canUpload($request, $engagement), 403);
        $request->validate(['file' => $this->fileRules()]);
        $this->service->addVersion($document, $request->file('file'), $request->user());

        return back()->with('status', 'New version uploaded. Working papers relying on this document need to be signed off again.');
    }

    public function download(Request $request, Engagement $engagement, Document $document, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('view', $engagement);

        return $this->service->download($version->setRelation('document', $document), $request->user());
    }

    public function archive(Request $request, Engagement $engagement, Document $document): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless(Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.archive_documents'), 403);
        $this->service->archive($document, $request->user());

        return redirect()->route('documents.index', $engagement)->with('status', 'Document archived. Its versions remain in the audit file.');
    }

    /** @return list<mixed> */
    private function fileRules(): array
    {
        $ext = config('audira.documents.extensions');

        return ['required', 'file', 'max:'.config('audira.documents.max_kb'), 'extensions:'.implode(',', $ext), new \App\Rules\ContentMatchesExtension];
    }

    private function canUpload(Request $request, Engagement $engagement): bool
    {
        return ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.upload_documents');
    }
}
