<?php

namespace App\Http\Controllers\Firm;

use App\Enums\ReportingFramework;
use App\Http\Controllers\Controller;
use App\Services\Firm\FirmProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FirmProfileController extends Controller
{
    public function edit(Request $request): View
    {
        Gate::authorize('firm.manage');

        return view('firm.edit', ['firm' => $request->user()->firm]);
    }

    public function update(Request $request, FirmProfileService $service): RedirectResponse
    {
        Gate::authorize('firm.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'registration_no' => ['nullable', 'string', 'max:40'],
            'audit_firm_no' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:10'],
            'default_framework' => ['required', Rule::enum(ReportingFramework::class)],
        ]);

        $service->update($request->user()->firm, $data, $request->user());

        return back()->with('status', 'Firm profile saved.');
    }
}
