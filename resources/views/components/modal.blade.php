@props(['name', 'title'])
<div x-data="{ open: false }" x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true" x-on:keydown.escape.window="open = false">
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-navy-950/50" @click="open = false"></div>
            <div class="relative w-full max-w-md rounded-lg bg-white shadow-xl" x-transition>
                <div class="card-header"><h3 class="card-title">{{ $title }}</h3>
                    <button type="button" class="btn-ghost" @click="open = false" aria-label="Close">&times;</button></div>
                <div class="card-body">{{ $slot }}</div>
            </div>
        </div>
    </template>
</div>
