<?php

namespace App\Http\Controllers\Completion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\PortalFsController;
use App\Models\ClientFsVersion;
use App\Models\Engagement;
use App\Services\ClientFs\ClientFsService;
use App\Support\Authorization\Permissions;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** The audit team reviews management's financial statements; it cannot edit them. */
class ClientFsReviewController extends Controller
{
    public function __construct(private ClientFsService $service) {}

    public function show(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $fs = $engagement->clientFinancialStatement;
        $versions = $fs?->versions()->with(['submitter', 'reviewer'])->get() ?? collect();
        $shown = $request->filled('version') ? $versions->firstWhere('uuid', $request->query('version')) : $versions->first();
        $content = $shown?->content;

        return view('completion.client-fs', [
            'engagement' => $engagement->load('client'),
            'fs' => $fs,
            'versions' => $versions,
            'shown' => $shown,
            'content' => $content,
            'checks' => $content ? $this->service->checks($content) : null,
            'tie' => $content ? $this->service->tieout($fs, $content) : null,
            'portalUsers' => $engagement->client->portalUsers()->count(),
            'canReview' => ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.prepare_report'),
            'pdf' => app(PdfRenderer::class)->available(),
        ]);
    }

    public function review(Request $request, Engagement $engagement, ClientFsVersion $version): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($version->statement->engagement_id === $engagement->id, 404);
        $data = $request->validate(['decision' => ['required', 'in:accept,request_changes'], 'comment' => ['nullable', 'string', 'max:5000']]);
        $this->service->review($version, $data['decision'], $data['comment'] ?? null, $request->user());

        return back()->with('status', $data['decision'] === 'accept' ? "Version {$version->version} accepted for the audit report." : 'Management has been asked for changes in the portal.');
    }

    public function reopen(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $fs = $engagement->clientFinancialStatement ?? abort(404);
        $this->service->reopen($fs, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $request->user());

        return back()->with('status', 'The statements are open again for management to revise.');
    }

    public function tieout(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $fs = $engagement->clientFinancialStatement ?? abort(404);
        $this->service->saveTieout($fs, (array) $request->validate(['map' => ['array'], 'map.*' => ['nullable', 'string', 'max:200']])['map'] ?? [], $request->user());

        return back()->with('status', 'Tie-out saved.');
    }

    public function pdf(Request $request, Engagement $engagement, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $engagement);
        $fs = $engagement->clientFinancialStatement ?? abort(404);
        $version = $fs->versions()->where('uuid', (string) $request->query('version'))->firstOrFail();

        return PortalFsController::pdfResponse($pdf, view('pdf.client-fs', ['engagement' => $engagement->load(['client', 'firm']), 'version' => $version, 'content' => $version->content])->render(),
            Str::slug($engagement->client->name.' financial statements v'.$version->version).'.pdf');
    }

    public function reportPdf(Request $request, Engagement $engagement, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $engagement);
        $report = $engagement->auditReport ?? abort(404);
        abort_if(blank($report->body), 404);

        return PortalFsController::pdfResponse($pdf, view('pdf.audit-report', ['engagement' => $engagement->load(['client', 'firm']), 'report' => $report->load('signer')])->render(),
            Str::slug($engagement->code.' auditors report'.($report->isSigned() ? ' signed' : ' draft')).'.pdf');
    }
}
