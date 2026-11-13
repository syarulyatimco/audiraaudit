@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}Client portal · AUDIRA</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script defer src="{{ asset('js/alpine.min.js') }}"></script>
</head>
<body class="min-h-screen bg-canvas">
    <header class="border-b border-navy-100 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
            <a href="{{ route('portal.dashboard') }}" class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-md bg-navy-900 text-sm font-bold text-teal-400">A</div>
                <div><div class="text-sm font-semibold text-navy-900">{{ auth()->user()->firm->name }}</div><div class="text-2xs uppercase tracking-widest text-ink-500">Client portal</div></div>
            </a>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-ink-600">{{ auth()->user()->name }} · {{ auth()->user()->client->name }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-ghost btn-sm">Sign out</button></form>
            </div>
        </div>
    </header>
    <main class="mx-auto max-w-5xl px-6 py-8">
        <x-flash />
        {{ $slot }}
    </main>
</body>
</html>
