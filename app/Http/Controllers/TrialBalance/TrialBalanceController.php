<?php

namespace App\Http\Controllers\TrialBalance;

use App\Enums\TbImportStatus;
use App\Enums\TbPeriod;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\TbImport;
use App\Rules\ContentMatchesExtension;
use App\Services\TrialBalance\LeadScheduleService;
use App\Services\TrialBalance\TrialBalanceImportService;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrialBalanceController extends Controller
{
    public function __construct(private TrialBalanceImportService $imports) {}

    public function index(Request $request, Engagement $engagement, LeadScheduleService $schedules): View
    {
        Gate::authorize('view', $engagement);
        $all = $engagement->tbImports()->with(['importer', 'document'])->latest()->get();

        return view('trial-balance.index', [
            'engagement' => $engagement->load('client'),
            'active' => $all->where('status', TbImportStatus::Active)->keyBy(fn ($i) => $i->period->value),
            'pending' => $all->where('status', TbImportStatus::Pending),
            'history' => $all->whereIn('status', [TbImportStatus::Superseded, TbImportStatus::Discarded]),
            'summary' => $schedules->build($engagement),
            'canImport' => $this->can($request, $engagement, 'engagement.import_trial_balance'),
        ]);
    }

    public function upload(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.import_trial_balance'), 403);
        $request->validate([
            'period' => ['required', Rule::enum(TbPeriod::class)],
            'file' => ['required', 'file', 'max:'.config('audira.trial_balance.max_kb'), 'extensions:csv,xlsx,txt', new ContentMatchesExtension],
        ]);

        $import = $this->imports->upload($engagement, $request->file('file'), TbPeriod::from($request->input('period')), $request->user());

        return redirect()->route('trial-balance.configure', [$engagement, $import])->with('status', 'File read. Confirm the columns to import.');
    }

    public function configure(Engagement $engagement, TbImport $tbImport): View
    {
        $import = $tbImport;
        Gate::authorize('view', $engagement);
        $rows = $import->raw_rows;
        $width = collect($rows)->map(fn ($r) => count($r))->max() ?: 1;

        return view('trial-balance.configure', [
            'engagement' => $engagement->load('client'),
            'import' => $import,
            'preview' => array_slice($rows, 0, 40, true),
            'totalRows' => count($rows),
            'width' => $width,
            'map' => $import->column_map ?? [],
        ]);
    }

    public function activate(Request $request, Engagement $engagement, TbImport $tbImport): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.import_trial_balance'), 403);
        $map = $request->validate([
            'header_row' => ['required', 'integer', 'min:1', 'max:100'],
            'mode' => ['required', Rule::in(['debit_credit', 'balance'])],
            'code' => ['required', 'integer', 'min:0', 'max:200'],
            'name' => ['required', 'integer', 'min:0', 'max:200', 'different:code'],
            'debit' => ['nullable', 'required_if:mode,debit_credit', 'integer', 'min:0', 'max:200'],
            'credit' => ['nullable', 'required_if:mode,debit_credit', 'integer', 'min:0', 'max:200', 'different:debit'],
            'balance' => ['nullable', 'required_if:mode,balance', 'integer', 'min:0', 'max:200'],
        ]);
        $map = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $map);

        $this->imports->activate($tbImport, $map, $request->user());

        return redirect()->route('trial-balance.mapping', $engagement)->with('status', 'Trial balance imported and balanced. Map any unmapped accounts below.');
    }

    public function discard(Request $request, Engagement $engagement, TbImport $tbImport): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.import_trial_balance'), 403);
        $this->imports->discard($tbImport, $request->user());

        return redirect()->route('trial-balance.index', $engagement)->with('status', 'Import discarded.');
    }

    /** Adjusted trial balance with mapping, as CSV (for the audit file or client discussion). */
    public function export(Request $request, Engagement $engagement, LeadScheduleService $schedules, ActivityLogger $logger): StreamedResponse
    {
        Gate::authorize('view', $engagement);
        $accounts = $schedules->accounts($engagement);
        $fslis = $engagement->fslis()->get()->keyBy('id');
        $logger->log('tb.exported', 'Adjusted trial balance exported', $engagement, [], $request->user());
        $money = fn (int $sen) => number_format($sen / 100, 2, '.', '');

        return response()->streamDownload(function () use ($accounts, $fslis, $money) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Account code', 'Account name', 'FSLI code', 'FSLI', 'Prior year', 'Current year (unadjusted)', 'Adjustments', 'Current year (adjusted)']);
            foreach ($accounts as $a) {
                $f = $a['fsli_id'] ? $fslis->get($a['fsli_id']) : null;
                // Neutralise spreadsheet formula injection in text cells.
                $safe = fn (?string $v) => $v !== null && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v;
                fputcsv($out, [$safe($a['code']), $safe($a['name']), $f?->code, $f?->name, $money($a['py']), $money($a['cy']), $money($a['adj']), $money($a['cy_adj'])]);
            }
            fclose($out);
        }, 'adjusted-trial-balance-'.$engagement->code.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function can(Request $request, Engagement $engagement, string $perm): bool
    {
        return ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, $perm);
    }
}
