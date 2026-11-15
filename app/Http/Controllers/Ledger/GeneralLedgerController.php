<?php

namespace App\Http\Controllers\Ledger;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\GlImport;
use App\Models\JetTest;
use App\Models\JetTestItem;
use App\Models\SamplingPlan;
use App\Services\Ledger\GeneralLedgerService;
use App\Services\Ledger\JournalTestingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GeneralLedgerController extends Controller
{
    public function __construct(private GeneralLedgerService $gl, private JournalTestingService $jet) {}

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $active = $engagement->activeGlImport();

        return view('ledger.index', [
            'engagement' => $engagement,
            'active' => $active,
            'imports' => $engagement->glImports()->with('importer')->limit(10)->get(),
            'documents' => $engagement->documents()->active()->orderByDesc('id')->limit(50)->get(['uuid', 'title']),
            'tests' => $engagement->jetTests()->withCount(['items', 'items as exceptions_count' => fn ($q) => $q->where('result', 'exception')])->get(),
            'canImport' => ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.import_trial_balance'),
            'filter' => $request->query('show', 'differences'),
        ]);
    }

    public function upload(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['file' => ['nullable', 'file', 'max:51200'], 'document' => ['nullable', 'uuid']]);
        $import = $this->gl->upload($engagement, $request->file('file'), $data['document'] ?? null, $request->user());

        return redirect()->route('ledger.configure', [$engagement, $import])->with('status', 'Check the columns AUDIRA detected, then import.');
    }

    public function configure(Engagement $engagement, GlImport $glImport): View
    {
        Gate::authorize('view', $engagement);
        abort_unless($glImport->engagement_id === $engagement->id, 404);

        return view('ledger.configure', ['engagement' => $engagement, 'import' => $glImport, 'fields' => [
            'date' => 'Date *', 'account' => 'Account code *', 'name' => 'Account name', 'reference' => 'Reference / doc no', 'description' => 'Description',
            'debit' => 'Debit', 'credit' => 'Credit', 'amount' => 'Amount (signed, instead of Dr/Cr)', 'journal' => 'Journal / source type', 'user' => 'User',
        ]]);
    }

    public function activate(Request $request, Engagement $engagement, GlImport $glImport): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($glImport->engagement_id === $engagement->id, 404);
        $import = $this->gl->activate($glImport, $request->validate(['header_row' => ['required', 'integer', 'min:1'], '*' => ['nullable']]), $request->user());
        $r = $import->reconciliation;

        return redirect()->route('ledger.index', $engagement)->with('status', "{$import->line_count} postings imported. ".($r['differences'] ? "{$r['differences']} account(s) do not agree to the TB — see the reconciliation." : 'Every account agrees to the TB.'));
    }

    public function discard(Request $request, Engagement $engagement, GlImport $glImport): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($glImport->engagement_id === $engagement->id, 404);
        $this->gl->discard($glImport, $request->user());

        return redirect()->route('ledger.index', $engagement)->with('status', 'Import discarded.');
    }

    /** Postings for one account, or for every account mapped to an FSLI. */
    public function accounts(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $gl = $engagement->activeGlImport() ?? abort(404);
        $fsli = $request->filled('fsli') ? $engagement->fslis()->where('code', $request->query('fsli'))->first() : null;
        $codes = $fsli ? $engagement->accountMappings()->where('engagement_fsli_id', $fsli->id)->pluck('account_code')->all() : array_filter([(string) $request->query('account')]);
        $q = $gl->lines()->whereIn('account_code', $codes)->orderBy('account_code')->orderBy('posted_on')->orderBy('seq');
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('description', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('reference', 'ilike', '%'.addcslashes($s, '%_\\').'%'));
        }

        return view('ledger.accounts', [
            'engagement' => $engagement, 'gl' => $gl, 'fsli' => $fsli, 'codes' => $codes,
            'totals' => (clone $q)->reorder()->toBase()->selectRaw('count(*) as n, coalesce(sum(debit_sen),0) as dr, coalesce(sum(credit_sen),0) as cr')->first(),
            'lines' => $q->paginate(100)->withQueryString(),
        ]);
    }

    public function journalTesting(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $gl = $engagement->activeGlImport();
        $hasCriteria = $request->hasAny(['weekend', 'dates', 'year_end_days', 'round_rm', 'threshold_rm', 'users', 'rare_users_max', 'journal_types', 'keywords', 'accounts']);
        $criteria = $this->jet->criteria($request->query(), $engagement);
        $pm = $engagement->currentMateriality();

        return view('ledger.journal-testing', [
            'engagement' => $engagement, 'gl' => $gl, 'criteria' => $criteria, 'input' => $request->query(),
            'result' => $gl && $hasCriteria ? $this->jet->candidates($gl, $criteria) : null,
            'pm' => $pm?->pm_sen,
            'users' => $gl ? $gl->lines()->whereNotNull('posted_by')->toBase()->selectRaw('posted_by, count(*) as n')->groupBy('posted_by')->orderBy('n')->limit(30)->get() : collect(),
            'types' => $gl ? $gl->lines()->whereNotNull('journal_type')->toBase()->selectRaw('journal_type, count(*) as n')->groupBy('journal_type')->orderByDesc('n')->limit(20)->get() : collect(),
            'canSave' => ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_programme'),
            'labels' => JournalTestingService::LABELS,
        ]);
    }

    public function saveTest(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $test = $this->jet->save($engagement, $this->jet->criteria($request->all(), $engagement), (string) $request->input('title'), $request->user());

        return redirect()->route('ledger.jet.show', [$engagement, $test])->with('status', "{$test->reference} saved. Record a result for each entry.");
    }

    public function showTest(Request $request, Engagement $engagement, JetTest $jetTest): View
    {
        Gate::authorize('view', $engagement);
        abort_unless($jetTest->engagement_id === $engagement->id, 404);

        return view('ledger.jet-test', [
            'engagement' => $engagement, 'test' => $jetTest->load('concluder'),
            'items' => $jetTest->items()->with(['line', 'tester', 'evidence'])->get()->sortBy(fn ($i) => $i->line->seq)->values(),
            'documents' => $engagement->documents()->active()->orderByDesc('id')->limit(100)->get(['uuid', 'title']),
            'labels' => JournalTestingService::LABELS,
            'canTest' => ! $engagement->status->isLocked() && $jetTest->status === 'open' && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.execute_procedures'),
            'canConclude' => ! $engagement->status->isLocked() && $jetTest->status === 'open' && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_programme'),
        ]);
    }

    public function recordItem(Request $request, Engagement $engagement, JetTest $jetTest, JetTestItem $item): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($jetTest->engagement_id === $engagement->id && $item->jet_test_id === $jetTest->id, 404);
        $d = $request->validate(['result' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:2000'], 'evidence' => ['nullable', 'uuid']]);
        $this->jet->record($item, $d['result'], $d['note'] ?? null, $d['evidence'] ?? null, $request->user());

        return back()->with('status', 'Result recorded.');
    }

    public function conclude(Request $request, Engagement $engagement, JetTest $jetTest): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($jetTest->engagement_id === $engagement->id, 404);
        $this->jet->conclude($jetTest, (string) $request->input('conclusion'), $request->user());

        return back()->with('status', "{$jetTest->reference} concluded.");
    }

    public function samplingPopulation(Request $request, Engagement $engagement, SamplingPlan $samplingPlan): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($samplingPlan->engagement_id === $engagement->id, 404);
        $d = $request->validate(['fsli' => ['nullable', 'string', 'max:20'], 'accounts' => ['nullable', 'string', 'max:2000'], 'side' => ['required', 'string']]);
        $codes = filled($d['fsli'] ?? null)
            ? $engagement->accountMappings()->whereHas('fsli', fn ($q) => $q->where('code', $d['fsli']))->pluck('account_code')->all()
            : preg_split('/[\s,;]+/', (string) ($d['accounts'] ?? ''));
        $n = $this->gl->populationFor($samplingPlan, $codes, $d['side'], $request->user());

        return back()->with('status', "{$n} GL postings loaded as the population.");
    }
}
