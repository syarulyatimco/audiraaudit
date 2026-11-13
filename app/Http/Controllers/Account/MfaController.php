<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Auth\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaController extends Controller
{
    public function __construct(private MfaService $mfa) {}

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasMfaEnabled()) {
            return redirect()->route('account.edit');
        }
        $secret = $request->session()->get('mfa.pending_secret') ?? $this->mfa->newSecret();
        $request->session()->put('mfa.pending_secret', $secret);

        return view('account.mfa-setup', [
            'qr' => $this->mfa->qrSvg($user, $secret),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'required' => $user->requiresMfa(),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        $secret = $request->session()->get('mfa.pending_secret');
        if (! $secret) {
            return redirect()->route('mfa.setup');
        }

        $codes = $this->mfa->enable($request->user(), $secret, $request->input('code'));
        $request->session()->forget('mfa.pending_secret');
        $request->session()->put(MfaService::SESSION_KEY, now()->timestamp);
        $request->session()->regenerate();

        return redirect()->route('account.edit')
            ->with('status', 'Two-factor authentication is on.')
            ->with('recovery_codes', $codes);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasMfaEnabled() || $request->session()->has(MfaService::SESSION_KEY)) {
            return redirect()->intended(route('dashboard'));
        }

        return view('auth.mfa-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! $this->mfa->verify($request->user(), $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        $request->session()->put(MfaService::SESSION_KEY, now()->timestamp);
        $request->session()->regenerate();

        $fallback = $request->user()->isSuperadmin() ? route('admin.dashboard') : route('dashboard');

        return redirect()->intended($fallback);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        return back()->with('status', 'New recovery codes generated. Previous codes no longer work.')
            ->with('recovery_codes', $this->mfa->regenerateRecoveryCodes($request->user()));
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $this->mfa->disable($request->user());
        $request->session()->forget(MfaService::SESSION_KEY);

        return back()->with('status', 'Two-factor authentication turned off.');
    }
}
