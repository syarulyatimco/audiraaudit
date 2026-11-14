<x-layouts.app title="Methodology">
    <x-page-header title="Audit methodology" eyebrow="Firm" subtitle="Programme libraries used to build each engagement's planning, controls and substantive programmes." />
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Library</th><th>Owner</th><th class="text-right">Programmes</th><th>Based on</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
            @foreach ($libraries as $l)
                <tr class="row-link">
                    <td><a href="{{ route('methodology.show', $l) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $l->name }}</a></td>
                    <td class="text-xs">@if ($l->isPlatform())<span class="badge badge-teal">AUDIRA template</span>@else<span class="badge badge-neutral">Firm</span>@endif</td>
                    <td class="text-right tabular-nums">{{ $l->programmes_count }}</td>
                    <td class="text-xs text-ink-500">{{ $l->basedOn?->name ?? '—' }}</td>
                    <td><span class="badge {{ $l->status === 'active' ? 'badge-success' : 'badge-neutral' }}">{{ ucfirst($l->status) }}</span></td>
                    <td class="text-xs text-ink-500">{{ local_time($l->updated_at, 'j M Y') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($libraries->isEmpty())<x-empty title="No methodology libraries" description="The AUDIRA template is installed by the platform seeder." />@endif
    </div>
    <p class="mt-4 max-w-3xl text-xs text-ink-500">The AUDIRA template is written in AUDIRA's own words and names the ISA each step responds to; it does not reproduce standards text. @if ($canManage) Open it and choose “Make a firm copy” to adapt programmes, steps and routing to your firm. @endif</p>
</x-layouts.app>
