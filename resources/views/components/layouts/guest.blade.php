@props(['title' => null, 'wide' => false])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}AUDIRA</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script defer src="{{ asset('js/alpine.min.js') }}"></script>
</head>
<body class="min-h-screen bg-canvas">
<div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
    <div class="relative hidden overflow-hidden bg-navy-950 p-12 lg:flex lg:flex-col">
        <div class="flex items-center gap-2.5">
            <div class="flex h-9 w-9 items-center justify-center rounded-md bg-teal-500 font-bold text-navy-950">A</div>
            <span class="text-base font-bold tracking-[0.2em] text-white">AUDIRA</span>
        </div>
        <div class="mt-auto max-w-md">
            <p class="text-2xs font-semibold uppercase tracking-widest text-teal-300">Audit management for Malaysian practices</p>
            <h1 class="mt-4 text-3xl font-semibold leading-tight text-white">Run every engagement — from acceptance to sign-off — in one controlled workspace.</h1>
            <ul class="mt-8 space-y-3 text-sm text-navy-200">
                <li class="flex gap-3"><span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-400"></span>MFRS, MPERS and MPSAS engagements with ISA-aligned workflow</li>
                <li class="flex gap-3"><span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-400"></span>AI agents prepare; partners approve every judgement</li>
                <li class="flex gap-3"><span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-400"></span>Firm-isolated data with a tamper-evident audit trail</li>
            </ul>
        </div>
        <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full border border-white/5"></div>
        <div class="pointer-events-none absolute -right-8 top-16 h-40 w-40 rounded-full border border-teal-400/10"></div>
    </div>
    <div class="flex items-center justify-center px-6 py-12">
        <div class="w-full {{ $wide ? 'max-w-xl' : 'max-w-sm' }}">
            <div class="mb-8 flex items-center gap-2.5 lg:hidden">
                <div class="flex h-8 w-8 items-center justify-center rounded-md bg-navy-900 font-bold text-teal-400">A</div>
                <span class="font-bold tracking-[0.2em] text-navy-900">AUDIRA</span>
            </div>
            {{ $slot }}
        </div>
    </div>
</div>
</body>
</html>
