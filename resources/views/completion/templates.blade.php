<x-layouts.app title="Report templates">
    <x-page-header title="Auditor's report templates" subtitle="Your firm's approved report wording for each opinion type. AUDIRA does not supply standard wording: paste the text your firm has approved, using placeholders for engagement details." />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2" x-data="{ tab: @js(old('opinion_tab', 'unmodified')) }">
            <div class="inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-sm">
                @foreach (\App\Enums\AuditOpinion::options() as $k => $l)
                    <button type="button" class="rounded px-3 py-1.5 font-medium" :class="tab === '{{ $k }}' ? 'bg-navy-800 text-white' : 'text-ink-600'" @click="tab = '{{ $k }}'">{{ $l }}@if(isset($templates[$k])) ✓@endif</button>
                @endforeach
            </div>
            @foreach (\App\Enums\AuditOpinion::options() as $k => $l)
                <form method="POST" action="{{ route('report-templates.update', $k) }}" class="card" x-show="tab === '{{ $k }}'" x-cloak>
                    @csrf @method('PUT')
                    <input type="hidden" name="opinion_tab" value="{{ $k }}">
                    <div class="card-header"><h2 class="card-title">{{ $l }}</h2>@if(isset($templates[$k]))<span class="text-xs text-ink-500">Updated {{ local_time($templates[$k]->updated_at) }}</span>@endif</div>
                    <div class="card-body"><textarea name="body" rows="22" class="input font-mono text-xs">{{ old('opinion_tab') === $k ? old('body') : ($templates[$k]->body ?? '') }}</textarea>@error('body')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save template</button></div>
                </form>
            @endforeach
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Placeholders</h2></div>
            <ul class="card-body space-y-1 text-sm">@foreach ($placeholders as $p)<li class="font-mono text-xs">{!! e('{'.'{'.$p.'}'.'}') !!}</li>@endforeach</ul>
            <p class="px-5 pb-4 text-xs text-ink-500">Empty optional paragraphs (going concern, emphasis of matter, other matter) are removed. Anything in [square brackets beginning "Insert"] blocks sign-off until replaced.</p>
        </div>
    </div>
</x-layouts.app>
