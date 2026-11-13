<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\AgentRegistry;
use App\Ai\Runtime\AiOrchestrator;
use App\Enums\MappingSource;
use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\Engagement;
use App\Services\TrialBalance\MappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiRunController extends Controller
{
    public function index(Engagement $engagement, AgentRegistry $agents, AiOrchestrator $orchestrator): View
    {
        Gate::authorize('view', $engagement);

        return view('ai.runs', [
            'engagement' => $engagement->load('client'),
            'runs' => $engagement->aiRuns()->with('requester')->get(),
            'agents' => $agents->all(),
            'unavailable' => $orchestrator->unavailableReasons($engagement),
        ]);
    }

    public function store(Request $request, Engagement $engagement, AiOrchestrator $orchestrator, AgentRegistry $agents): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate([
            'agent' => ['required', Rule::in(array_keys($agents->all()))],
            'task' => ['required', 'string', 'max:40'],
            'subject' => ['nullable', 'uuid'],
        ]);
        $input = [];
        if (filled($data['subject'] ?? null)) {
            // A task about one record (e.g. a sampling plan) — the record must belong to this engagement.
            abort_unless($engagement->samplingPlans()->where('uuid', $data['subject'])->exists()
                || $engagement->documents()->where('uuid', $data['subject'])->exists(), 404);
            $input['subject'] = $data['subject'];
        }
        $run = $orchestrator->start($engagement, $data['agent'], $data['task'], $request->user(), $input);

        return back()->with('status', 'AI task queued. Results appear here when it finishes; nothing is applied until you accept it.')->with('ai_run', $run->uuid);
    }

    public function show(Request $request, Engagement $engagement, AiRun $aiRun, AgentRegistry $agents): View|JsonResponse
    {
        Gate::authorize('view', $engagement);
        if ($request->wantsJson()) {
            return response()->json(['status' => $aiRun->status, 'label' => $aiRun->statusLabel(), 'finished' => ! $aiRun->isActive()]);
        }

        return view('ai.run', [
            'engagement' => $engagement->load('client'),
            'run' => $aiRun->load(['steps', 'requester']),
            'agent' => $agents->get($aiRun->agent),
        ]);
    }

    /** Accept selected AI mapping suggestions (re-validated now; recorded with source = ai). */
    public function acceptMappings(Request $request, Engagement $engagement, AiRun $aiRun, MappingService $mapping): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['codes' => ['required', 'array', 'max:5000'], 'codes.*' => ['string', 'max:50']]);
        if ($aiRun->agent !== 'accounting_tb' || $aiRun->task !== 'suggest_mappings' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'These AI results cannot be applied.']);
        }

        $fslis = $engagement->fslis()->pluck('id', 'code');
        $unmapped = app(\App\Services\TrialBalance\LeadScheduleService::class)->accounts($engagement)->whereNull('fsli_id');
        $map = [];
        foreach ($aiRun->output['suggestions'] ?? [] as $s) {
            if (in_array($s['account_code'], $data['codes'], true) && $unmapped->has($s['account_code']) && isset($fslis[$s['fsli_code']])) {
                $map[$s['account_code']] = $fslis[$s['fsli_code']];
            }
        }
        $n = $map ? $mapping->apply($engagement, $map, $request->user(), MappingSource::Ai) : 0;

        return back()->with('status', "{$n} AI suggestion(s) accepted.");
    }

    /** Copy an AI materiality recommendation into the open draft (the team edits it and submits it for approval). */
    public function acceptMateriality(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Planning\MaterialityService $materiality, \App\Services\Planning\PlanningPapers $papers): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['revision_reason' => ['nullable', 'string', 'max:5000']]);
        $out = $aiRun->output ?? [];
        if ($aiRun->agent !== 'materiality' || $aiRun->task !== 'recommend_materiality' || ! $aiRun->isUsable() || ! ($out['usable'] ?? false)) {
            throw ValidationException::withMessages(['ai' => 'This recommendation cannot be used.']);
        }
        $alternatives = collect($out['alternatives'] ?? [])->map(fn ($a) => "- {$a['benchmark']}: {$a['reason_rejected']}")->join("\n");

        $draft = $materiality->saveDraft($engagement, [
            'stage' => 'revised',
            'benchmark' => $out['benchmark'],
            'benchmark_basis' => $out['benchmark_year'],
            'benchmark_reason' => null,
            'om_percentage' => $out['om_percentage'], 'pm_percentage' => $out['pm_percentage'], 'ct_percentage' => $out['ct_percentage'],
            'rationale' => trim($out['rationale'].($alternatives ? "\n\nAlternatives considered:\n{$alternatives}" : '')),
            'pm_rationale' => $out['pm_rationale'], 'ct_rationale' => $out['ct_rationale'],
            'revision_reason' => $data['revision_reason'] ?? null,
            'specifics' => collect($out['specifics'] ?? [])->map(fn ($s) => ['area' => $s['area'], 'amount' => (string) ($s['amount_sen'] / 100), 'rationale' => $s['rationale']])->all(),
        ], $request->user(), $aiRun);

        $this->cite($engagement, $papers->paper($engagement, \App\Models\WorkingPaper::MATERIALITY, $request->user()), $out['citations'] ?? [], $request);

        return redirect()->route('materiality.index', $engagement)->with('status', "AI recommendation copied into draft v{$draft->version}. Review every figure and rationale before submitting it for approval.");
    }

    /** Add selected AI-proposed risks to the register (re-validated by RiskService). */
    public function acceptRisks(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Planning\RiskService $risks, \App\Services\Planning\PlanningPapers $papers): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate([
            'selected' => ['required', 'array', 'max:12'], 'selected.*' => ['integer', 'min:0'],
            'significant' => ['nullable', 'array'], 'significant.*' => ['integer', 'min:0'],
        ]);
        // Form values arrive as strings; compare as integers.
        $selected = array_map('intval', $data['selected']);
        $significant = array_map('intval', $data['significant'] ?? []);
        if ($aiRun->agent !== 'risk_assessment' || $aiRun->task !== 'suggest_risks' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'These AI results cannot be applied.']);
        }
        $fslis = $engagement->fslis()->pluck('id', 'code');
        $existing = $engagement->risks()->active()->pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->all();
        $paper = null;
        $n = 0;
        foreach ($aiRun->output['risks'] ?? [] as $i => $r) {
            if (! in_array($i, $selected, true) || in_array(mb_strtolower(trim($r['title'])), $existing, true)) {
                continue;
            }
            $risks->create($engagement, [
                'title' => $r['title'],
                'description' => trim($r['description']."\n\nBasis: ".$r['basis']),
                'level' => $r['level'],
                'fsli_ids' => collect($r['fsli_codes'])->map(fn ($c) => $fslis[$c] ?? null)->filter()->values()->all(),
                'assertions' => $r['assertions'], 'inherent_factors' => $r['inherent_factors'],
                'likelihood' => $r['likelihood'], 'magnitude' => $r['magnitude'],
                'is_significant' => in_array($i, $significant, true),
                'is_fraud_risk' => $r['fraud'],
                'planned_response' => $r['planned_response'],
            ], $request->user(), 'ai', $aiRun);
            $paper ??= $papers->paper($engagement, \App\Models\WorkingPaper::RISK_ASSESSMENT, $request->user());
            $this->cite($engagement, $paper, $r['citations'] ?? [], $request);
            $n++;
        }

        return redirect()->route('risks.index', $engagement)->with('status', "{$n} AI-proposed risk(s) added to the register. Review each one; significant risks still need partner approval.");
    }

    /** Add selected AI-drafted procedures to the programme (re-validated by ProgrammeService). */
    public function acceptProcedures(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Programme\ProgrammeService $programme, \App\Services\Planning\PlanningPapers $papers): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['selected' => ['required', 'array', 'max:15'], 'selected.*' => ['integer', 'min:0']]);
        if ($aiRun->agent !== 'audit_programme' || $aiRun->task !== 'draft_procedures' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'These AI results cannot be applied.']);
        }
        $selected = array_map('intval', $data['selected']);
        $risks = $engagement->risks()->active()->pluck('id', 'reference');
        $fslis = $engagement->fslis()->pluck('id', 'code');
        $existing = $engagement->procedures()->active()->pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->all();
        $paper = null;
        $n = 0;
        foreach ($aiRun->output['procedures'] ?? [] as $i => $p) {
            if (! in_array($i, $selected, true) || in_array(mb_strtolower(trim($p['title'])), $existing, true)) {
                continue;
            }
            $programme->create($engagement, [
                'title' => $p['title'], 'steps' => $p['steps'], 'nature' => $p['nature'], 'timing' => $p['timing'], 'assertions' => $p['assertions'],
                'engagement_fsli_id' => $p['fsli_code'] ? ($fslis[$p['fsli_code']] ?? null) : null,
                'risk_ids' => collect($p['risk_references'])->map(fn ($r) => $risks[$r] ?? null)->filter()->values()->all(),
            ], $request->user(), 'ai', $aiRun);
            $paper ??= $papers->paper($engagement, \App\Models\WorkingPaper::AUDIT_PROGRAMME, $request->user());
            $this->cite($engagement, $paper, $p['citations'] ?? [], $request);
            $n++;
        }

        return redirect()->route('programme.index', $engagement)->with('status', "{$n} AI-drafted procedure(s) added. Assign each one and review the steps.");
    }

    /** Copy AI-recommended parameters into a draft sampling plan; AUDIRA computes the size. */
    public function acceptSampling(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Sampling\SamplingService $sampling): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $out = $aiRun->output ?? [];
        $plan = $engagement->samplingPlans()->where('uuid', $aiRun->input['subject'] ?? '')->firstOrFail();
        if ($aiRun->agent !== 'sampling' || ! $aiRun->isUsable() || ! ($out['usable'] ?? false)) {
            throw ValidationException::withMessages(['ai' => 'This recommendation cannot be used.']);
        }
        $rm = fn ($sen) => $sen === null ? null : number_format($sen / 100, 2, '.', '');
        $sampling->saveParameters($plan, [
            'control_total' => $rm($plan->control_total_sen), 'control_total_source' => $plan->control_total_source, 'reconciliation_note' => $plan->reconciliation_note,
            'confidence_level' => $out['confidence_level'],
            'tolerable_misstatement' => $rm($out['tolerable_misstatement_sen']), 'expected_misstatement' => $rm($out['expected_misstatement_sen']),
            'key_item_threshold' => $rm($out['key_item_threshold_sen']),
            'tolerable_deviation_rate' => $out['tolerable_deviation_rate'], 'expected_deviation_rate' => $out['expected_deviation_rate'],
            'expansion_factor' => $plan->expansion_factor, 'assurance_factor' => $plan->assurance_factor,
            'strata' => array_map(fn ($b) => $rm($b), $plan->strata ?? []),
            'method_rationale' => trim(($plan->method_rationale ? $plan->method_rationale."\n\n" : '').'AI-recommended parameters: '.$out['rationale']),
        ], $request->user(), $aiRun);

        return redirect()->route('sampling.show', [$engagement, $plan])->with('status', 'AI-recommended parameters applied to the draft. AUDIRA recalculated the sample size; review before submitting for approval.');
    }

    /** Save an AI-drafted client message as a draft; a person edits and sends it. */
    public function acceptMessage(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Portal\MessageService $messages): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless(\App\Support\Authorization\Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.message_client'), 403);
        if ($aiRun->agent !== 'client_communication' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'This draft cannot be used.']);
        }
        if ($engagement->messages()->where('ai_run_id', $aiRun->id)->exists()) {
            return back()->with('status', 'This AI draft is already in your drafts.');
        }
        $out = $aiRun->output;
        $ids = $engagement->pbcItems()->whereIn('reference', $out['pbc_references'] ?? [])->pluck('id')->all();
        $messages->draft($engagement, $out['subject'], $out['body'], $request->user(), $aiRun, $ids);

        return back()->with('status', 'AI draft saved to your message drafts. Review and edit it before sending.');
    }

    /** Record an accepted document extraction and, optionally, link the document to the suggested request. */
    public function acceptExtraction(Request $request, Engagement $engagement, AiRun $aiRun): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless(\App\Support\Authorization\Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_pbc'), 403);
        $data = $request->validate(['link' => ['nullable', 'boolean']]);
        if ($aiRun->agent !== 'document_intelligence' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'This extraction cannot be used.']);
        }
        $doc = $engagement->documents()->where('uuid', $aiRun->input['subject'] ?? '')->with('currentVersion')->firstOrFail();
        $out = $aiRun->output;
        \Illuminate\Support\Facades\DB::transaction(function () use ($doc, $aiRun, $out, $request, $data, $engagement) {
            \App\Models\DocumentExtraction::updateOrCreate(['document_version_id' => $doc->current_version_id], [
                'document_id' => $doc->id, 'ai_run_id' => $aiRun->id, 'document_type' => $out['document_type'],
                'summary' => $out['summary'], 'fields' => $out['fields'], 'accepted_by' => $request->user()->id,
            ]);
            if (($data['link'] ?? false) && $out['suggested_pbc_reference']) {
                $item = $engagement->pbcItems()->where('reference', $out['suggested_pbc_reference'])->first();
                $item?->documents()->syncWithoutDetaching([$doc->id => ['firm_id' => $doc->firm_id, 'linked_by' => $request->user()->id]]);
            }
            app(\App\Support\Audit\ActivityLogger::class)->log('document.extraction_accepted', "AI extraction accepted for {$doc->title}", $doc, ['type' => $out['document_type']], $request->user());
        });

        return back()->with('status', 'Extraction recorded on the document.');
    }

    /** Record verified AI citations on a planning paper (only published paragraphs; duplicates ignored). */
    private function cite(Engagement $engagement, \App\Models\WorkingPaper $paper, array $citations, Request $request): void
    {
        $service = app(\App\Services\WorkingPapers\WorkingPaperService::class);
        foreach ($citations as $c) {
            $p = \App\Models\LibraryParagraph::with('version.standard')->find($c['paragraph_id']);
            if ($p && $p->version->status === \App\Enums\LibraryVersionStatus::Published
                && \App\Support\Authorization\Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.edit_working_papers')) {
                $service->addCitation($paper, $p, 'AI-suggested: '.mb_substr($c['relevance'] ?? '', 0, 250), $request->user());
            }
        }
    }

    /** Record selected AI-drafted test results; the accepting auditor becomes the tester of record. */
    public function acceptTestResults(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Sampling\SamplingService $sampling): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless(\App\Support\Authorization\Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.execute_procedures'), 403);
        if ($aiRun->agent !== 'testing_evidence' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'These results cannot be used.']);
        }
        $plan = $engagement->samplingPlans()->where('uuid', $aiRun->input['subject'] ?? '')->firstOrFail();
        $selected = array_map('intval', (array) $request->input('selected', []));
        $results = $aiRun->output['results'] ?? [];
        $n = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($selected, $results, $plan, $sampling, $request, $aiRun, &$n) {
            foreach ($selected as $i) {
                $r = $results[$i] ?? null;
                if (! $r) {
                    continue;
                }
                $item = $plan->sampleItems()->whereNull('result')->whereHas('populationItem', fn ($q) => $q->where('item_ref', $r['item_ref']))->first();
                if (! $item) {
                    continue; // already tested since the run
                }
                $sampling->recordResult($item, [
                    'result' => $r['result'],
                    'audited_amount' => $r['audited_amount_sen'] !== null ? number_format($r['audited_amount_sen'] / 100, 2, '.', '') : null,
                    'result_note' => trim($r['note']."\nEvidence: \"".$r['evidence_quote']."\" (AI draft reviewed and accepted)"),
                    'evidence_document' => $r['document_id'],
                ], $request->user());
                $n++;
            }
            app(\App\Support\Audit\ActivityLogger::class)->log('ai.accepted', "{$n} AI-drafted test result(s) accepted for sample {$plan->reference}", $plan, ['ai_run' => $aiRun->uuid], $request->user());
        });

        return redirect()->route('sampling.show', [$engagement, $plan])->with('status', "{$n} result(s) recorded. You are the tester of record for each.");
    }

    /** Record selected AI-drafted findings as open findings flagged as AI drafts. */
    public function acceptFindings(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Findings\FindingService $findings): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless(\App\Support\Authorization\Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_findings'), 403);
        if ($aiRun->agent !== 'findings' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'These drafts cannot be used.']);
        }
        $selected = array_map('intval', (array) $request->input('selected', []));
        $drafts = $aiRun->output['findings'] ?? [];
        $created = [];
        \Illuminate\Support\Facades\DB::transaction(function () use ($selected, $drafts, $engagement, $findings, $request, $aiRun, &$created) {
            foreach ($selected as $i) {
                $f = $drafts[$i] ?? null;
                if (! $f || \App\Models\Finding::where('ai_run_id', $aiRun->id)->where('title', $f['title'])->exists()) {
                    continue;
                }
                $created[] = $findings->create($engagement, [
                    'type' => $f['type'], 'title' => $f['title'], 'description' => $f['description'], 'cause' => $f['cause'], 'recommendation' => $f['recommendation'],
                    'engagement_fsli_id' => $f['fsli_code'] ? $engagement->fslis()->where('code', $f['fsli_code'])->value('id') : null,
                    'procedure_id' => $f['procedure_reference'] ? $engagement->procedures()->where('reference', $f['procedure_reference'])->value('id') : null,
                    'sampling_plan_id' => $f['plan_reference'] ? $engagement->samplingPlans()->where('reference', $f['plan_reference'])->value('id') : null,
                    'misstatement_class' => $f['misstatement_class'], 'amount_sen' => $f['amount_sen'], 'deficiency_severity' => $f['deficiency_severity'],
                ], $request->user(), $aiRun)->reference;
            }
        });

        return redirect()->route('findings.index', $engagement)->with('status', count($created) ? 'Recorded '.implode(', ', $created).' as AI-drafted findings. Review each, and decide significance yourself.' : 'Nothing new to record.');
    }

    /** Save selected AI-drafted checklist responses on items that are still open. */
    public function acceptChecklist(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\Completion\CompletionService $completion): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless(\App\Support\Authorization\Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_completion'), 403);
        if ($aiRun->agent !== 'completion' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'These drafts cannot be used.']);
        }
        $responses = collect($aiRun->output['responses'] ?? [])->values();
        $n = 0;
        foreach (array_map('intval', (array) $request->input('selected', [])) as $i) {
            $r = $responses[$i] ?? null;
            $item = $r ? $engagement->completionItems()->where('key', $r['key'])->where('status', 'open')->first() : null;
            if ($item) {
                $completion->draftResponse($item, $r['response'], $request->user(), $aiRun);
                $n++;
            }
        }

        return redirect()->route('completion.index', $engagement)->with('status', "{$n} draft response(s) saved. Review each and mark it done yourself.");
    }

    /** Raise selected AI-drafted review notes in the accepting person's name. */
    public function acceptReviewNotes(Request $request, Engagement $engagement, AiRun $aiRun, \App\Services\WorkingPapers\WorkingPaperService $papers): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        if ($aiRun->agent !== 'quality_review' || ! $aiRun->isUsable()) {
            throw ValidationException::withMessages(['ai' => 'These notes cannot be used.']);
        }
        $notes = $aiRun->output['notes'] ?? [];
        $n = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $engagement, $notes, $papers, &$n) {
            foreach (array_map('intval', (array) $request->input('selected', [])) as $i) {
                $note = $notes[$i] ?? null;
                $wp = $note ? $engagement->workingPapers()->where('reference', $note['working_paper'])->first() : null;
                if ($wp) {
                    $papers->raiseNote($wp, $note['note'].' (from AI pre-review)', $request->user());
                    $n++;
                }
            }
        });

        return back()->with('status', "{$n} review note(s) raised in your name.");
    }
}
