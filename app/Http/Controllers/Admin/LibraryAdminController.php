<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LibraryCategory;
use App\Http\Controllers\Controller;
use App\Models\LibraryFramework;
use App\Models\LibraryParagraph;
use App\Models\LibraryStandard;
use App\Models\LibraryVersion;
use App\Services\Library\LibraryService;
use App\Support\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Superadmin curation of the platform Audit Library. */
class LibraryAdminController extends Controller
{
    public function __construct(private LibraryService $service, private ActivityLogger $logger) {}

    public function index(): View
    {
        return view('admin.library.index', [
            'frameworks' => LibraryFramework::orderBy('sort_order')->with(['standards.versions'])->get(),
        ]);
    }

    public function storeFramework(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_\-]+$/', 'unique:library_frameworks,code'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(LibraryCategory::class)],
            'issuer' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $fw = LibraryFramework::create([...$data, 'sort_order' => (int) LibraryFramework::max('sort_order') + 1]);
        $this->logger->log('library.framework_created', "Library framework {$fw->name} created", $fw, [], $request->user(), null);

        return back()->with('status', "Framework {$fw->name} added.");
    }

    public function storeStandard(Request $request, LibraryFramework $framework): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:60', Rule::unique('library_standards', 'reference')->where('framework_id', $framework->id)],
            'title' => ['required', 'string', 'max:255'],
        ]);
        $standard = $framework->standards()->create([...$data, 'sort_order' => (int) $framework->standards()->max('sort_order') + 1]);
        $this->logger->log('library.standard_created', "Standard {$standard->reference} added to {$framework->name}", $standard, [], $request->user(), null);

        return back()->with('status', "{$standard->reference} added.");
    }

    public function storeVersion(Request $request, LibraryStandard $standard): RedirectResponse
    {
        $data = $request->validate([
            'version_label' => ['required', 'string', 'max:80', Rule::unique('library_versions', 'version_label')->where('standard_id', $standard->id)],
            'effective_date' => ['nullable', 'date'],
            'source_title' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'url:https', 'max:500'],
            'change_summary' => ['nullable', 'string', 'max:2000'],
            'source_file' => ['nullable', 'file', 'max:51200', 'extensions:pdf,docx,doc,txt', 'mimes:pdf,docx,doc,txt'],
        ]);
        $version = $this->service->createVersion($standard, collect($data)->except('source_file')->all(), $request->file('source_file'), $request->user());

        return redirect()->route('admin.library.versions.show', $version)->with('status', 'Draft version created. Add paragraphs from the authorised source, then publish.');
    }

    public function showVersion(LibraryVersion $version): View
    {
        return view('admin.library.version', ['version' => $version->load(['standard.framework', 'paragraphs', 'publisher'])]);
    }

    public function storeParagraph(Request $request, LibraryVersion $version): RedirectResponse
    {
        $this->service->addParagraph($version, $this->paragraphData($request));

        return back()->with('status', 'Paragraph added.');
    }

    public function updateParagraph(Request $request, LibraryVersion $version, LibraryParagraph $paragraph): RedirectResponse
    {
        abort_unless($paragraph->version_id === $version->id, 404);
        $this->service->updateParagraph($paragraph, $this->paragraphData($request, $paragraph));

        return back()->with('status', 'Paragraph updated.');
    }

    public function destroyParagraph(LibraryVersion $version, LibraryParagraph $paragraph): RedirectResponse
    {
        abort_unless($paragraph->version_id === $version->id, 404);
        $this->service->deleteParagraph($paragraph);

        return back()->with('status', 'Paragraph removed.');
    }

    public function import(Request $request, LibraryVersion $version): RedirectResponse
    {
        $request->validate(['csv' => ['required', 'file', 'max:10240', 'extensions:csv,txt']]);
        $n = $this->service->importCsv($version, $request->file('csv'), $request->user());

        return back()->with('status', "{$n} paragraphs imported.");
    }

    public function attachSource(Request $request, LibraryVersion $version): RedirectResponse
    {
        $request->validate(['source_file' => ['required', 'file', 'max:51200', 'extensions:pdf,docx,doc,txt', 'mimes:pdf,docx,doc,txt']]);
        $this->service->attachSource($version, $request->file('source_file'));

        return back()->with('status', 'Source file attached.');
    }

    public function publish(Request $request, LibraryVersion $version): RedirectResponse
    {
        $this->service->publish($version, $request->user());

        return back()->with('status', 'Version published. Firms now cite this version; the previous one is marked superseded.');
    }

    public function source(LibraryVersion $version): StreamedResponse
    {
        abort_unless($version->source_path, 404);

        return Storage::disk($version->source_disk)->download($version->source_path, $version->source_filename ?? 'source');
    }

    /** @return array{paragraph_ref: string, heading: ?string, body: string} */
    private function paragraphData(Request $request, ?LibraryParagraph $existing = null): array
    {
        return $request->validate([
            'paragraph_ref' => ['required', 'string', 'max:40'],
            'heading' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);
    }
}
