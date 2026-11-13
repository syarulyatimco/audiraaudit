<?php

namespace App\Http\Controllers\WorkingPapers;

use App\Enums\WorkingPaperSection;
use App\Enums\WorkingPaperStatus;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\WorkingPaper;
use App\Services\WorkingPapers\WorkingPaperService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkingPaperController extends Controller
{
    public function __construct(private WorkingPaperService $service) {}

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $papers = $engagement->workingPapers()
            ->with(['activeSignoffs.user'])
            ->withCount(['reviewNotes as open_notes_count' => fn ($q) => $q->where('status', '!=', 'cleared'), 'documents'])
            ->get();

        $stats = [
            'total' => $papers->count(),
            'reviewed' => $papers->where('status', WorkingPaperStatus::Reviewed)->count(),
            'prepared' => $papers->where('status', WorkingPaperStatus::Prepared)->count(),
            'open_notes' => $papers->sum('open_notes_count'),
        ];

        return view('working-papers.index', [
            'engagement' => $engagement->load('client'),
            'grouped' => $papers->groupBy(fn ($p) => $p->section->value),
            'stats' => $stats,
            'canEdit' => $this->can($request, $engagement, 'engagement.edit_working_papers'),
        ]);
    }

    public function create(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.edit_working_papers'), 403);
        $section = WorkingPaperSection::tryFrom((string) $request->query('section')) ?? WorkingPaperSection::Planning;

        return view('working-papers.create', [
            'engagement' => $engagement->load('client'),
            'section' => $section,
            'suggested' => collect(WorkingPaperSection::cases())->mapWithKeys(fn ($s) => [$s->value => $this->service->suggestReference($engagement, $s)]),
        ]);
    }

    public function store(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.edit_working_papers'), 403);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-\/]+$/',
                Rule::unique('working_papers', 'reference')->where('engagement_id', $engagement->id)],
            'title' => ['required', 'string', 'max:200'],
            'section' => ['required', Rule::enum(WorkingPaperSection::class)],
            'objective' => ['nullable', 'string', 'max:5000'],
        ]);
        $wp = $this->service->create($engagement, $data, $request->user());

        return redirect()->route('working-papers.show', [$engagement, $wp])->with('status', "Working paper {$wp->reference} created.");
    }

    public function show(Request $request, Engagement $engagement, WorkingPaper $workingPaper): View|RedirectResponse
    {
        Gate::authorize('view', $engagement);
        if ($workingPaper->isEntityUnderstanding()) {
            return redirect()->route('entity-understanding.show', $engagement);
        }
        $workingPaper->load(['signoffs.user', 'reviewNotes.raiser', 'reviewNotes.responder', 'reviewNotes.clearer', 'documents.currentVersion', 'citations.paragraph.version.standard', 'creator']);

        return view('working-papers.show', $this->viewData($request, $engagement, $workingPaper));
    }

    public function update(Request $request, Engagement $engagement, WorkingPaper $workingPaper): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.edit_working_papers'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'work_performed' => ['nullable', 'string', 'max:100000'],
            'conclusion' => ['nullable', 'string', 'max:10000'],
        ]);
        $before = $workingPaper->status;
        $this->service->update($workingPaper, $data, $request->user());
        $msg = $before !== WorkingPaperStatus::Draft && $workingPaper->fresh()->status === WorkingPaperStatus::Draft
            ? 'Saved. Existing sign-offs were invalidated because the content changed.' : 'Saved.';

        return back()->with('status', $msg);
    }

    public function signoff(Request $request, Engagement $engagement, WorkingPaper $workingPaper): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['role' => ['required', Rule::in(['preparer', 'reviewer'])]]);

        $data['role'] === 'preparer'
            ? $this->service->signAsPreparer($workingPaper, $request->user())
            : $this->service->signAsReviewer($workingPaper, $request->user());

        return back()->with('status', $data['role'] === 'preparer' ? 'Signed off as preparer.' : 'Signed off as reviewer.');
    }

    /** @return array<string, mixed> */
    public static function permissionsFor(Request $request, Engagement $engagement): array
    {
        $u = $request->user();
        $locked = $engagement->status->isLocked();

        return [
            'canEdit' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.edit_working_papers'),
            'canPrepare' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.prepare_working_papers'),
            'canReview' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.review_working_papers'),
            'canRaiseNotes' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.raise_review_notes'),
        ];
    }

    /** @return array<string, mixed> */
    private function viewData(Request $request, Engagement $engagement, WorkingPaper $wp): array
    {
        return [
            'engagement' => $engagement->load(['client', 'members']),
            'wp' => $wp,
            'linkable' => $engagement->documents()->active()->whereNotIn('id', $wp->documents->pluck('id'))->orderBy('title')->get(),
            ...self::permissionsFor($request, $engagement),
        ];
    }

    private function can(Request $request, Engagement $engagement, string $perm): bool
    {
        return ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, $perm);
    }
}
