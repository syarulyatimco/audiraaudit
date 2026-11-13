<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Superadmin platform screens operate across firms (firm metadata, plans, subscriptions only —
 * they expose no client or engagement data). Runs after EnsureSuperadmin.
 */
class RunAsPlatformSystem
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperadmin(), 403);

        return $this->tenant->runAsSystem(fn () => $next($request));
    }
}
