<x-layouts.app :title="'Sampling · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto lg:col-span-2">
            <div class="card-header"><div><h2 class="card-title">Sampling plans</h2><p class="text-xs text-ink-500">Population → completeness → documented size → approval → reproducible selection → results → evaluation</p></div></div>
            @if ($plans->isEmpty())
                <x-empty title="No sampling plans" description="Create a plan for a procedure that tests a sample of items." />
            @else
                <table class="table">
                    <thead><tr><th>Ref</th><th>Plan</th><th>Method</th><th class="text-right">Population</th><th class="text-right">Size</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($plans as $p)
                        <tr class="row-link">
                            <td class="font-mono text-xs font-semibold">{{ $p->reference }}</td>
                            <td><a href="{{ route('sampling.show', [$engagement, $p]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $p->title }}</a><div class="text-xs text-ink-500">{{ $p->procedure?->reference }} {{ $p->fsli?->name }}</div></td>
                            <td class="text-xs">{{ $p->method->label() }}<div class="text-ink-500">{{ $p->approach->label() }}</div></td>
                            <td class="text-right tabular-nums text-xs">{{ number_format($p->population_count) }} items<div>{{ amount($p->population_value_sen) }}</div></td>
                            <td class="text-right tabular-nums">{{ $p->sample_size ?? '—' }}</td>
                            <td><x-status :value="$p->status" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @if ($canManage)
            <form method="POST" action="{{ route('sampling.store', $engagement) }}" class="card" x-data="{ method: 'mus', type: 'monetary' }">
                @csrf
                <div class="card-header"><h2 class="card-title">New sampling plan</h2></div>
                <div class="card-body space-y-4">
                    <x-field name="title" label="Title" required />
                    <x-field name="objective" label="Objective — what the test is designed to achieve" type="textarea" rows="2" required />
                    <x-select name="procedure_id" label="Procedure" :options="$procedures->mapWithKeys(fn ($p) => [$p->id => $p->reference.' '.$p->title])->all()" :value="request('procedure')" placeholder="None" />
                    <x-select name="engagement_fsli_id" label="FSLI (for completeness reconciliation)" :options="$fslis->pluck('name', 'id')->all()" placeholder="None" />
                    <div><label class="label" for="test_type">Test type</label><select id="test_type" name="test_type" class="input" x-model="type">@foreach (\App\Enums\SampleTestType::options() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label class="label" for="method">Selection method</label><select id="method" name="method" class="input" x-model="method">@foreach (\App\Enums\SamplingMethod::options() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div x-show="!['mus','judgemental','haphazard'].includes(method)"><label class="label" for="approach">Approach</label><select id="approach" name="approach" class="input">@foreach (\App\Enums\SamplingApproach::options() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <p class="text-xs text-ink-500" x-show="['judgemental','haphazard'].includes(method)">Judgemental and haphazard selections are always non-statistical and are labelled as such.</p>
                    <p class="text-xs text-ink-500" x-show="method === 'mus'">Monetary unit sampling is statistical and applies to monetary tests.</p>
                </div>
                <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Create plan</button></div>
            </form>
        @endif
    </div>
</x-layouts.app>
