<?php

namespace App\Http\Controllers\Library;

use App\Enums\LibraryVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\LibraryFramework;
use App\Models\LibraryParagraph;
use App\Models\LibraryStandard;
use App\Models\LibraryVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only library for firm users: published and superseded versions only (never drafts). */
class LibraryController extends Controller
{
    public function index(): View
    {
        $frameworks = LibraryFramework::where('is_active', true)->orderBy('sort_order')
            ->with(['standards.publishedVersion' => fn ($q) => $q->withCount('paragraphs')])->get();

        return view('library.index', compact('frameworks'));
    }

    public function standard(Request $request, LibraryStandard $standard): View
    {
        $standard->load(['framework', 'versions' => fn ($q) => $q->where('status', '!=', LibraryVersionStatus::Draft->value)]);
        $version = $request->query('version')
            ? $standard->versions->firstWhere('uuid', $request->query('version'))
            : $standard->versions->firstWhere('status', LibraryVersionStatus::Published);
        abort_if($request->query('version') && ! $version, 404);
        $version?->load('paragraphs');

        return view('library.standard', compact('standard', 'version'));
    }

    public function search(Request $request): View|JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'min:2', 'max:200']]);
        $q = trim($data['q'] ?? '');

        $results = $q === '' ? collect() : LibraryParagraph::query()
            ->whereHas('version', fn ($v) => $v->where('status', LibraryVersionStatus::Published->value))
            ->where(fn ($w) => $w->whereRaw("search @@ websearch_to_tsquery('english', ?)", [$q])
                ->orWhereHas('version.standard', fn ($s) => $s->where('reference', 'ilike', '%'.addcslashes($q, '%_\\').'%')))
            ->orderByRaw("ts_rank(search, websearch_to_tsquery('english', ?)) desc", [$q])
            ->with('version.standard.framework')->limit(30)->get();

        if ($request->wantsJson()) {
            return response()->json($results->map(fn (LibraryParagraph $p) => [
                'id' => $p->id,
                'citation' => $p->citation(),
                'standard' => $p->version->standard->title,
                'heading' => $p->heading,
                'excerpt' => \Illuminate\Support\Str::limit($p->body, 240),
            ]));
        }

        return view('library.search', ['q' => $q, 'results' => $results]);
    }
}
