<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\WorkingPaper;
use App\Services\Planning\StrategyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StrategyController extends Controller
{
    use PlanningContext;

    public function show(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);

        return view('planning.strategy', [
            ...$this->paperData($request, $engagement, WorkingPaper::AUDIT_STRATEGY, 'engagement.edit_strategy'),
            'strategy' => $engagement->auditStrategy()->with('editor')->first(),
            'sections' => config('audira.audit_strategy.sections'),
            'required' => config('audira.audit_strategy.required'),
            'materiality' => $engagement->currentMateriality(),
            'significant' => $engagement->risks()->active()->where('is_significant', true)->get(),
            'team' => $engagement->members()->with('user')->get(),
        ]);
    }

    public function update(Request $request, Engagement $engagement, StrategyService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $keys = array_keys(config('audira.audit_strategy.sections'));
        $data = $request->validate([
            'sections' => ['nullable', 'array'],
            'sections.*' => ['nullable', 'string', 'max:20000'],
            'controls_approach' => ['required', Rule::in(['substantive', 'combined'])],
            'planning_meeting_on' => ['nullable', 'date'],
            'fieldwork_start_on' => ['nullable', 'date'],
            'fieldwork_end_on' => ['nullable', 'date'],
            'report_target_on' => ['nullable', 'date'],
        ]);
        $data['sections'] = array_intersect_key($data['sections'] ?? [], array_flip($keys));
        $service->save($engagement, $data, $request->user());

        return back()->with('status', 'Audit strategy saved.');
    }
}
