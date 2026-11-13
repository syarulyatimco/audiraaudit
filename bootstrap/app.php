<?php

use App\Http\Middleware\EnsureFirmIsActive;
use App\Http\Middleware\EnsureMfa;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\RunAsPlatformSystem;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetTenantContext::class, \App\Http\Middleware\SecurityHeaders::class]);

        // Tenant context must exist before route-model binding so scoped bindings resolve
        // (and foreign IDs 404 instead of leaking existence).
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureMfa::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureFirmIsActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RunAsPlatformSystem::class);

        $middleware->alias([
            'firm.active' => EnsureFirmIsActive::class,
            'mfa' => EnsureMfa::class,
            'superadmin' => EnsureSuperadmin::class,
            'platform.system' => RunAsPlatformSystem::class,
            'portal' => \App\Http\Middleware\EnsurePortalUser::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo(fn ($request) => $request->user()?->isSuperadmin() ? '/admin' : ($request->user()?->isClientUser() ? '/portal' : '/'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
