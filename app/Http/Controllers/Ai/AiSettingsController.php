<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\AgentRegistry;
use App\Ai\Gateway\ModelRouter;
use App\Ai\Runtime\AiBudget;
use App\Ai\Runtime\AiSettingsService;
use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\AiUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiSettingsController extends Controller
{
    public function edit(Request $request, AgentRegistry $agents, AiBudget $budget, ModelRouter $router): View
    {
        Gate::authorize('ai.manage');
        $firm = $request->user()->firm;
        $start = now(config('audira.display_timezone'))->startOfMonth()->utc();

        return view('ai.settings', [
            'firm' => $firm,
            'agents' => $agents->all(),
            'allowance' => $budget->allowance($firm),
            'used' => $budget->used($firm),
            'configured' => $router->isConfigured(),
            'provider' => config('ai.default_provider'),
            'tokens' => AiUsage::where('created_at', '>=', $start)->selectRaw('coalesce(sum(input_tokens),0) as i, coalesce(sum(output_tokens),0) as o')->first(),
            'runs' => AiRun::with(['engagement.client', 'requester'])->latest('id')->limit(15)->get(),
        ]);
    }

    public function update(Request $request, AiSettingsService $service, AgentRegistry $agents): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $data = $request->validate([
            'ai_enabled' => ['boolean'],
            'acknowledge' => ['nullable', 'boolean'],
            'enabled_agents' => ['array'],
            'enabled_agents.*' => [Rule::in(array_keys($agents->all()))],
        ]);
        // Switching AI on for the first time requires the data-processing acknowledgement.
        if ($request->boolean('ai_enabled') && ! $request->user()->firm->ai_enabled && ! $request->boolean('acknowledge')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['acknowledge' => 'Confirm the data-processing statement to enable AI.']);
        }

        $disabled = array_values(array_diff(array_keys($agents->all()), $data['enabled_agents'] ?? []));
        $service->update($request->user()->firm, $request->boolean('ai_enabled'), $disabled, $request->user());

        return back()->with('status', 'AI settings saved.');
    }
}
