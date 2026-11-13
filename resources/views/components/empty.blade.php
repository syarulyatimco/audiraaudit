@props(['title', 'description' => null])
<div class="flex flex-col items-center justify-center px-6 py-12 text-center">
    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-navy-50 text-navy-400">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
    </div>
    <div class="mt-3 text-sm font-semibold text-navy-900">{{ $title }}</div>
    @if ($description)<p class="mt-1 max-w-sm text-sm text-ink-500">{{ $description }}</p>@endif
    @if (! $slot->isEmpty())<div class="mt-4">{{ $slot }}</div>@endif
</div>
