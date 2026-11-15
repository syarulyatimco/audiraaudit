<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientFinancialStatement;
use App\Models\Engagement;
use App\Services\ClientFs\ClientFsService;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Management prepares its financial statements in the client portal; the audit team only reviews them. */
class PortalFsController extends Controller
{
    public function __construct(private ClientFsService $service) {}

    public function show(Request $request, Engagement $engagement): View
    {
        $this->assertOwn($request, $engagement);
        $fs = $engagement->clientFinancialStatement;

        return view('portal.financial-statements', [
            'engagement' => $engagement->load('client'),
            'fs' => $fs,
            'versions' => $fs?->versions()->with('reviewer')->get() ?? collect(),
            'checks' => $fs ? $this->service->checks(['statements' => $fs->statements]) : null,
            'templates' => collect(config('fs_templates'))->map(fn ($t) => $t['label']),
            'suggested' => match ($engagement->client->entity_type?->value) { 'jmb_mc' => 'jmb_mc', 'cooperative' => 'cooperative', default => 'company' },
            'locked' => $engagement->status->isLocked() || $fs?->status === 'accepted',
            'pdf' => app(PdfRenderer::class)->available(),
        ]);
    }

    public function start(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->assertOwn($request, $engagement);
        $this->service->start($engagement, (string) $request->validate(['template' => ['required', 'string', 'max:30']])['template'], $request->user());

        return back()->with('status', 'Your financial statements have been set up. Fill in the figures and notes, then submit them to your auditors.');
    }

    public function saveStatement(Request $request, Engagement $engagement, string $key): JsonResponse
    {
        $fs = $this->fs($request, $engagement);
        $data = $request->validate(['rows' => ['present', 'array', 'max:'.ClientFsService::MAX_ROWS]]);
        $this->service->saveStatement($fs, $key, $data['rows'], $request->user());
        $fs->refresh();

        return response()->json(['rows' => $fs->statements[$key]['rows'], 'checks' => $this->service->checks(['statements' => $fs->statements])]);
    }

    public function saveNotes(Request $request, Engagement $engagement): JsonResponse
    {
        $fs = $this->fs($request, $engagement);
        $this->service->saveNotes($fs, $request->validate(['notes' => ['present', 'array', 'max:100']])['notes'], $request->user());

        return response()->json(['notes' => $fs->fresh()->notes]);
    }

    public function submit(Request $request, Engagement $engagement): RedirectResponse
    {
        $fs = $this->fs($request, $engagement);
        $data = $request->validate([
            'directors_approved' => ['nullable', 'boolean'],
            'approved_by_names' => ['nullable', 'string', 'max:300'],
            'approved_on' => ['nullable', 'date'],
        ]);
        $v = $this->service->submit($fs, $request->user(), (bool) ($data['directors_approved'] ?? false), $data['approved_by_names'] ?? null, $data['approved_on'] ?? null);

        return back()->with('status', "Version {$v->version} sent to your audit team.");
    }

    public function export(Request $request, Engagement $engagement): BinaryFileResponse
    {
        $fs = $this->fs($request, $engagement);

        return response()->download($this->service->export($fs), Str::slug($engagement->client->name.' financial statements').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function import(Request $request, Engagement $engagement): RedirectResponse
    {
        $fs = $this->fs($request, $engagement);
        $file = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:xlsx']])['file'];
        $r = $this->service->import($fs, $file->getRealPath(), $request->user());

        return back()->with('status', "Excel applied: {$r['rows']} row(s) across {$r['statements']} statement(s).");
    }

    public function pdf(Request $request, Engagement $engagement, PdfRenderer $pdf): Response
    {
        $fs = $this->fs($request, $engagement);
        $version = $request->filled('version') ? $fs->versions()->where('uuid', $request->query('version'))->firstOrFail() : null;

        return self::pdfResponse($pdf, view('pdf.client-fs', ['engagement' => $engagement->load(['client', 'firm']), 'version' => $version,
            'content' => $version?->content ?? ['statements' => $fs->statements, 'notes' => $fs->notes]])->render(),
            Str::slug($engagement->client->name.' financial statements'.($version ? ' v'.$version->version : ' draft')).'.pdf');
    }

    public static function pdfResponse(PdfRenderer $pdf, string $html, string $name): Response
    {
        abort_unless($pdf->available(), 503, 'PDF output is not configured on this server.');

        return response($pdf->render($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function fs(Request $request, Engagement $engagement): ClientFinancialStatement
    {
        $this->assertOwn($request, $engagement);

        return $engagement->clientFinancialStatement ?? abort(404);
    }

    private function assertOwn(Request $request, Engagement $engagement): void
    {
        abort_unless($engagement->client_id === $request->user()->client_id && $engagement->status->value !== 'archived', 404);
    }
}
