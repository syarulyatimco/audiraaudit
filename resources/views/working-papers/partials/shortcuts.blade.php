{{-- Shortcuts pasted into this paper ([[step:…]] / [[doc:…]]), resolved live. --}}
@php
    $tokens = app(\App\Services\Methodology\LinkTokens::class);
    $found = $tokens->find($wp->objective, $wp->work_performed, $wp->conclusion);
    $resolved = $found ? $tokens->resolve($found) : collect();
@endphp
@if ($found)
    <div class="card">
        <div class="card-header"><h2 class="card-title">Shortcuts in this paper</h2><span class="text-xs text-ink-500">{{ count($found) }}</span></div>
        <ul class="divide-y divide-navy-50">
            @foreach ($resolved as $link)
                <li class="px-5 py-2.5 text-sm">{!! $tokens->chip($link) !!}<div class="mt-0.5 truncate text-xs text-ink-500">{{ $link['title'] }}</div></li>
            @endforeach
        </ul>
    </div>
@endif
