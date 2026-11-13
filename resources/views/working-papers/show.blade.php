<x-layouts.app :title="$wp->reference.' · '.$wp->title">
    <x-engagement-nav :engagement="$engagement" />
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('working-papers.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← Working papers</a>
        <span class="text-ink-300">/</span>
        <span class="font-mono text-sm font-semibold text-navy-800">{{ $wp->reference }}</span>
        <span class="text-sm text-ink-500">{{ $wp->section->label() }}</span>
    </div>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('working-papers.update', [$engagement, $wp]) }}" class="card">
                @csrf @method('PUT')
                <div class="card-body space-y-5">
                    @if ($wp->status !== \App\Enums\WorkingPaperStatus::Draft && $canEdit)
                        <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">This paper is signed off. Saving any change invalidates the existing sign-offs (they are kept in the history).</p>
                    @endif
                    <x-field name="title" label="Title" :value="$wp->title" required :disabled="! $canEdit" />
                    <x-field name="objective" label="Objective" type="textarea" rows="3" :value="$wp->objective" :disabled="! $canEdit" />
                    <x-field name="work_performed" label="Work performed" type="textarea" rows="12" :value="$wp->work_performed" :disabled="! $canEdit" hint="Procedures performed, results and exceptions. Reference evidence by document title." />
                    <x-field name="conclusion" label="Conclusion" type="textarea" rows="4" :value="$wp->conclusion" :disabled="! $canEdit" />
                </div>
                @if ($canEdit)
                    <div class="flex items-center justify-between border-t border-navy-100/70 px-5 py-3">
                        <span class="text-xs text-ink-400">Created by {{ $wp->creator->name }} · last updated {{ local_time($wp->updated_at) }}</span>
                        <button class="btn-primary">Save</button>
                    </div>
                @endif
            </form>
        </div>
        <div class="space-y-6">
            @include('working-papers.partials.signoffs')
            @include('working-papers.partials.notes')
            @include('working-papers.partials.evidence')
        </div>
    </div>
</x-layouts.app>
