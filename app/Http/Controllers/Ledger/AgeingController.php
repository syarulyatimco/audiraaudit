<?php

namespace App\Http\Controllers\Ledger;

use App\Http\Controllers\Controller;
use App\Models\AgeingReport;
use App\Models\Engagement;
use App\Models\SamplingPlan;
use App\Services\Ledger\AgeingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AgeingController extends Controller
{
    public function __construct(private AgeingService $ageing) {}

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);

        return view('ledger.ageing-index', [
            'engagement' => $engagement,
            'reports' => $engagement->ageingReports()->whereIn('status', ['active', 'pending'])->with('fsli')->get(),
            'history' => $engagement->ageingReports()->whereIn('status', ['superseded'])->limit(10)->get(),
            'fslis' => $engagement->fslis()->orderBy('sort_order')->get(['id', 'code', 'name']),
            'documents' => $engagement->documents()->active()->orderByDesc('id')->limit(50)->get(['uuid', 'title']),
            'canImport' => ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.import_trial_balance'),
        ]);
    }

    public function upload(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $d = $request->validate(['file' => ['nullable', 'file', 'max:20480'], 'document' => ['nullable', 'uuid'], 'type' => ['required', 'string'], 'period' => ['required', 'string'],
            'as_at' => ['required', 'string', 'max:20'], 'engagement_fsli_id' => ['nullable', 'integer']]);
        $report = $this->ageing->upload($engagement, $request->file('file'), $d['document'] ?? null, $d, $request->user());

        return redirect()->route('ageing.show', [$engagement, $report])->with('status', 'Check the columns and the age of each column, then import.');
    }

    public function show(Request $request, Engagement $engagement, AgeingReport $ageingReport): View
    {
        Gate::authorize('view', $engagement);
        abort_unless($ageingReport->engagement_id === $engagement->id, 404);
        if ($ageingReport->status === 'pending') {
            return view('ledger.ageing-configure', ['engagement' => $engagement, 'report' => $ageingReport]);
        }

        return view('ledger.ageing-show', ['engagement' => $engagement, 'report' => $ageingReport->load('fsli'), 'a' => $this->ageing->analysis($ageingReport)]);
    }

    public function activate(Request $request, Engagement $engagement, AgeingReport $ageingReport): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($ageingReport->engagement_id === $engagement->id, 404);
        $r = $this->ageing->activate($ageingReport, $request->validate(['header_row' => ['required', 'integer', 'min:1'], 'code' => ['nullable'], 'name' => ['nullable'], 'total' => ['nullable'], 'buckets' => ['array']]), $request->user());

        return redirect()->route('ageing.show', [$engagement, $r])->with('status', "{$r->line_count} balances imported.");
    }

    public function discard(Request $request, Engagement $engagement, AgeingReport $ageingReport): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($ageingReport->engagement_id === $engagement->id, 404);
        $this->ageing->discard($ageingReport, $request->user());

        return redirect()->route('ageing.index', $engagement)->with('status', 'Listing discarded.');
    }

    public function samplingPopulation(Request $request, Engagement $engagement, SamplingPlan $samplingPlan): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($samplingPlan->engagement_id === $engagement->id, 404);
        $report = $engagement->ageingReports()->where('uuid', (string) $request->validate(['report' => ['required', 'uuid']])['report'])->firstOrFail();
        $n = $this->ageing->populationFor($samplingPlan, $report, $request->user());

        return back()->with('status', "{$n} balances from the ageing loaded as the population.");
    }
}
