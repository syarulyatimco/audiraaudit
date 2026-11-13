{{-- Right-hand column for a planning paper: $wp, $gates + sign-off/notes/evidence partials --}}
@if ($wp)
    <a href="{{ route('working-papers.show', [$engagement, $wp]) }}" class="block text-xs font-semibold uppercase tracking-wider text-ink-500 hover:text-navy-900">Working paper {{ $wp->reference }} →</a>
    @if ($gates && $wp->status === \App\Enums\WorkingPaperStatus::Draft)
        <div class="card card-body">
            <div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">Before preparer sign-off</div>
            <ul class="mt-2 space-y-1.5 text-sm text-ink-700">@foreach ($gates as $g)<li class="flex gap-2"><span class="text-amber-600">•</span>{{ $g }}</li>@endforeach</ul>
        </div>
    @endif
    @include('working-papers.partials.signoffs')
    @include('working-papers.partials.notes')
    @include('working-papers.partials.evidence')
@else
    <div class="card card-body text-sm text-ink-500">Not started. A team member with edit rights opens this paper to begin.</div>
@endif
