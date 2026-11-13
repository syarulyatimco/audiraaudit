<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The client portal is only for active client users of an active firm. */
class EnsurePortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isClientUser()) {
            abort(403, 'The client portal is for client contacts.');
        }
        if ($user->firm?->isSuspended()) {
            abort(403, 'This portal is temporarily unavailable.');
        }

        return $next($request);
    }
}
