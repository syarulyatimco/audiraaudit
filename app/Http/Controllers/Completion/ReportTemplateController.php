<?php

namespace App\Http\Controllers\Completion;

use App\Enums\AuditOpinion;
use App\Http\Controllers\Controller;
use App\Models\ReportTemplate;
use App\Services\Reporting\ReportService;
use App\Support\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The firm's approved auditor's-report wording, one template per opinion type. */
class ReportTemplateController extends Controller
{
    public function index(): View
    {
        Gate::authorize('report_templates.manage');

        return view('completion.templates', ['templates' => ReportTemplate::all()->keyBy(fn ($t) => $t->opinion->value), 'placeholders' => ReportService::PLACEHOLDERS]);
    }

    public function update(Request $request, string $opinion, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('report_templates.manage');
        $op = AuditOpinion::tryFrom($opinion) ?? abort(404);
        $body = $request->validate(['body' => ['required', 'string', 'max:100000']])['body'];
        $unknown = collect(preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $body, $m) ? $m[1] : [])->diff(ReportService::PLACEHOLDERS)->unique()->values();
        if ($unknown->isNotEmpty()) {
            return back()->withErrors(['body' => 'Unknown placeholder(s): '.$unknown->join(', ')])->withInput();
        }
        $t = ReportTemplate::updateOrCreate(['opinion' => $op->value], ['body' => $body, 'updated_by' => $request->user()->id]);
        $logger->log('report_template.saved', "Report template for {$op->label()} saved", $t, [], $request->user());

        return back()->with('status', "Template for {$op->label()} saved.");
    }
}
