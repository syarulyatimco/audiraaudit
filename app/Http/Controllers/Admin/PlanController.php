<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('admin.plans.index', ['plans' => Plan::orderBy('sort_order')->get()]);
    }

    public function create(): View
    {
        return view('admin.plans.form', ['plan' => new Plan(['currency' => 'MYR', 'min_paid_users' => 3, 'is_active' => true, 'ai_credits_per_user' => 0])]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $this->validated($request);
        $plan = DB::transaction(function () use ($data, $request, $logger) {
            $plan = Plan::create($data);
            $logger->log('platform.plan_created', "Plan {$plan->name} created", $plan, $data, $request->user());

            return $plan;
        });

        return redirect()->route('admin.plans.index')->with('status', "Plan {$plan->name} created.");
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.form', ['plan' => $plan]);
    }

    public function update(Request $request, Plan $plan, ActivityLogger $logger): RedirectResponse
    {
        $data = $this->validated($request, $plan);
        DB::transaction(function () use ($plan, $data, $request, $logger) {
            $before = $plan->only(array_keys($data));
            $plan->update($data);
            $logger->log('platform.plan_updated', "Plan {$plan->name} updated", $plan, ['before' => $before, 'after' => $data], $request->user());
        });

        return redirect()->route('admin.plans.index')->with('status', "Plan {$plan->name} updated. New prices apply to new or renewed subscriptions.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'price_rm' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'min_paid_users' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:100000', 'gte:min_paid_users'],
            'ai_credits_per_user' => ['required', 'integer', 'min:0', 'max:10000000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $data['price_per_user_sen'] = (int) round(((float) $data['price_rm']) * 100);
        unset($data['price_rm']);
        $data['is_active'] = $request->boolean('is_active');
        $data['currency'] = 'MYR';

        return $data;
    }
}
