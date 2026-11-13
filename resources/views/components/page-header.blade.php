@props(['title', 'subtitle' => null, 'eyebrow' => null])
<div class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)
            <div class="mb-1 text-2xs font-semibold uppercase tracking-widest text-teal-700">{{ $eyebrow }}</div>
        @endif
        <h1 class="truncate text-2xl font-semibold">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-ink-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
