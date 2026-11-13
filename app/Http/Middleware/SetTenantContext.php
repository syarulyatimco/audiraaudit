<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's firm as the tenant for this request (PHP scope + PostgreSQL RLS),
 * and restores the previous context afterwards so long-lived workers never leak a tenant.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $snapshot = $this->tenant->snapshot();
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Your account has been deactivated.']);
        }

        $this->tenant->set($user?->firm_id);

        try {
            return $next($request);
        } finally {
            $this->tenant->restore($snapshot);
        }
    }
}
