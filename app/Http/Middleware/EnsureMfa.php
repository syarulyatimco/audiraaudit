<?php

namespace App\Http\Middleware;

use App\Services\Auth\MfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * - MFA enabled but not yet verified this session → challenge.
 * - MFA mandatory for the role but not set up → setup (skipped with the "challenge" parameter,
 *   used on the account pages where setup happens).
 */
class EnsureMfa
{
    public function handle(Request $request, Closure $next, string $mode = 'full'): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->hasMfaEnabled()) {
            if (! $request->session()->has(MfaService::SESSION_KEY)) {
                $request->session()->put('url.intended', $request->fullUrl());

                return redirect()->route('mfa.challenge');
            }
        } elseif ($mode === 'full' && $user->requiresMfa()) {
            return redirect()->route('mfa.setup')->with('status', 'Two-factor authentication is required for your role. Set it up to continue.');
        }

        return $next($request);
    }
}
