<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Firm\FirmRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(Request $request): View
    {
        $plans = Plan::where('is_active', true)->orderBy('sort_order')->get();

        return view('auth.register', ['plans' => $plans, 'selected' => $request->query('plan')]);
    }

    public function store(Request $request, FirmRegistrationService $service): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'firm_name' => ['required', 'string', 'max:160'],
            'registration_no' => ['nullable', 'string', 'max:40'],
            'audit_firm_no' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'terms' => ['accepted'],
        ]);
        $user = $service->register($data);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome to AUDIRA. Your firm workspace is ready.');
    }
}
