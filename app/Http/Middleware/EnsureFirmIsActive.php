<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirmIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isClientUser()) {
            return redirect()->route('portal.dashboard');
        }
        if (! $user || ! $user->isFirmUser()) {
            abort(403, 'This area is for audit firm staff.');
        }

        if ($user->firm?->isSuspended()) {
            return response()->view('errors.firm-suspended', ['firm' => $user->firm], 403);
        }

        return $next($request);
    }
}
