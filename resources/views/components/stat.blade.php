@props(['label', 'value', 'hint' => null, 'href' => null])
@php $inner = '<div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">'.e($label).'</div><div class="mt-2 text-2xl font-semibold tabular-nums text-navy-900">'.e($value).'</div>'.($hint ? '<div class="mt-1 text-xs text-ink-500">'.e($hint).'</div>' : ''); @endphp
@if ($href)
    <a href="{{ $href }}" class="card block px-5 py-4 transition hover:border-teal-300">{!! $inner !!}</a>
@else
    <div class="card px-5 py-4">{!! $inner !!}</div>
@endif
