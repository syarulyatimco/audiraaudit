@props(['title' => null])
@php
    $user = auth()->user();
    $isAdmin = $user->isSuperadmin();
    $pendingCount = 0;
    if (! $isAdmin) {
        $svc = app(\App\Services\Approvals\ApprovalService::class);
        $pendingCount = \App\Models\Approval::pending()->visibleTo($user)->get()->filter(fn ($a) => $svc->canDecide($user, $a))->count();
    }
    $nav = $isAdmin ? [
        ['Platform', [
            ['admin.dashboard', 'Overview', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'admin.dashboard'],
            ['admin.firms.index', 'Audit firms', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'admin.firms.*'],
            ['admin.library.index', 'Audit library', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', 'admin.library.*'],
            ['admin.invoices.index', 'Invoices', 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z', 'admin.invoices.*'],
            ['admin.support.index', 'Support access', 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z', 'admin.support.*'],
            ['admin.plans.index', 'Plans & pricing', 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'admin.plans.*'],
        ]],
    ] : array_filter([
        ['Workspace', [
            ['dashboard', 'Dashboard', 'M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z', 'dashboard'],
            ['engagements.index', 'Engagements', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', ['engagements.*', 'working-papers.*', 'documents.*', 'entity-understanding.*', 'trial-balance.*', 'lead-schedules.*', 'adjustments.*']],
            ['clients.index', 'Clients', 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'clients.*'],
            ['library.index', 'Audit library', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', 'library.*'],
            ['approvals.index', 'Approvals', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'approvals.*'],
        ]],
        ($user->hasPermission('users.view') || $user->hasPermission('report_templates.manage') || $user->hasPermission('ai.manage') || $user->hasPermission('firm.manage') || $user->hasPermission('billing.view') || $user->hasPermission('activity.view')) ? ['Firm', array_values(array_filter([
            $user->hasPermission('users.view') ? ['users.index', 'People', 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', 'users.*'] : null,
            $user->hasPermission('firm.manage') ? ['firm.edit', 'Firm profile', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'firm.edit'] : null,
            $user->hasPermission('billing.view') ? ['firm.billing', 'Subscription', 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z', 'firm.billing'] : null,
            $user->hasPermission('ai.manage') ? ['firm.ai', 'AI settings', 'M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5', 'firm.ai'] : null,
            $user->hasPermission('support.grant') ? ['firm.support', 'Support access', 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z', 'firm.support'] : null,
            $user->hasPermission('report_templates.manage') ? ['report-templates.index', 'Report templates', 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z', 'report-templates.*'] : null,
            $user->hasPermission('activity.view') ? ['firm.activity', 'Audit trail', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'firm.activity'] : null,
        ]))] : null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}AUDIRA</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script defer src="{{ asset('js/app.js') }}"></script>
    <script defer src="{{ asset('js/alpine.min.js') }}"></script>
</head>
<body class="min-h-screen" x-data="{ sidebar: false }">
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-navy-950 transition lg:translate-x-0" :class="sidebar && 'translate-x-0'">
        <div class="flex h-16 items-center gap-2.5 px-5">
            <div class="flex h-8 w-8 items-center justify-center rounded-md bg-teal-500 text-sm font-bold text-navy-950">A</div>
            <div>
                <div class="text-sm font-bold tracking-[0.18em] text-white">AUDIRA</div>
                <div class="text-2xs text-navy-300">{{ $isAdmin ? 'Platform administration' : \Illuminate\Support\Str::limit($user->firm->name, 26) }}</div>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto px-3 pb-6">
            @foreach ($nav as [$section, $items])
                <div class="nav-section">{{ $section }}</div>
                @foreach ($items as [$route, $label, $icon, $pattern])
                    <a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($pattern) ? 'nav-link-active' : '' }}">
                        <svg class="h-4.5 h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                        <span class="flex-1">{{ $label }}</span>
                        @if ($route === 'approvals.index' && $pendingCount > 0)
                            <span class="rounded-full bg-teal-500 px-1.5 text-2xs font-bold text-navy-950">{{ $pendingCount }}</span>
                        @endif
                    </a>
                @endforeach
            @endforeach
        </nav>
        <div class="border-t border-white/5 px-5 py-4 text-2xs text-navy-400">
            AI assists. Auditors decide.
        </div>
    </aside>
    <div class="fixed inset-0 z-30 bg-navy-950/40 lg:hidden" x-show="sidebar" x-cloak @click="sidebar = false"></div>

    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-navy-100/70 bg-white/90 px-4 backdrop-blur sm:px-8">
            <button type="button" class="btn-ghost lg:hidden" @click="sidebar = true" aria-label="Open navigation">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="flex-1 truncate text-sm text-ink-500">{{ $breadcrumb ?? '' }}</div>
            @if (! $isAdmin && $user->firm->status === \App\Enums\FirmStatus::Trial && $user->firm->trial_ends_at)
                <a href="{{ $user->hasPermission('billing.view') ? route('firm.billing') : '#' }}" class="hidden rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-medium text-teal-800 sm:block">
                    Trial · {{ max(0, (int) now()->diffInDays($user->firm->trial_ends_at, false)) }} days left
                </a>
            @endif
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" class="flex items-center gap-2.5 rounded-md py-1 pl-1 pr-2 hover:bg-navy-50" @click="open = !open">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-navy-800 text-xs font-semibold text-white">{{ $user->initials() }}</span>
                    <span class="hidden text-left sm:block">
                        <span class="block text-sm font-medium leading-tight text-navy-900">{{ $user->name }}</span>
                        <span class="block text-2xs text-ink-500">{{ $user->roleLabel() }}</span>
                    </span>
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 mt-2 w-52 rounded-md border border-navy-100 bg-white py-1 shadow-lg">
                    <a href="{{ route('account.edit') }}" class="block px-4 py-2 text-sm text-ink-700 hover:bg-navy-50">Account & security</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="block w-full px-4 py-2 text-left text-sm text-ink-700 hover:bg-navy-50">Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 px-4 py-8 sm:px-8">
            <div class="mx-auto max-w-7xl">
                <x-flash />
                {{ $slot }}
            </div>
        </main>
    </div>
</div>
</body>
</html>
