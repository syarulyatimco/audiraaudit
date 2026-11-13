<x-layouts.app title="AI settings">
    <x-page-header title="AI settings" subtitle="AI agents prepare drafts for your team. They never approve, post, sign off or change audit records." />
    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('firm.ai.update') }}" class="card lg:col-span-2" x-data="{ on: {{ $firm->ai_enabled ? 'true' : 'false' }} }">
            @csrf @method('PUT')
            <div class="card-header"><h2 class="card-title">AI assistance</h2>
                @if ($firm->ai_enabled)<span class="badge badge-success badge-dot">On</span>@else<span class="badge badge-neutral badge-dot">Off</span>@endif</div>
            <div class="card-body space-y-5">
                @unless ($configured)
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">No AI provider is configured on this AUDIRA installation yet. Settings can be saved, but agents cannot run until the platform operator adds provider credentials.</div>
                @endunless
                <label class="flex items-start gap-3 text-sm">
                    <input type="hidden" name="ai_enabled" value="0">
                    <input type="checkbox" name="ai_enabled" value="1" class="checkbox mt-0.5" x-model="on" @checked($firm->ai_enabled)>
                    <span><span class="font-medium text-navy-900">Enable AI agents for {{ $firm->name }}</span>
                        <span class="block text-ink-500">Engagement data needed for a task (for example trial balance account names and balances) is sent to the configured AI provider ({{ ucfirst($provider) }}) when a team member starts that task.</span></span>
                </label>
                @unless ($firm->ai_enabled)
                    <label class="flex items-start gap-3 rounded-md border border-navy-100 bg-navy-50/40 p-3 text-sm" x-show="on" x-cloak>
                        <input type="checkbox" name="acknowledge" value="1" class="checkbox mt-0.5">
                        <span class="text-ink-700">I confirm the firm is permitted to process client engagement data with this AI provider (engagement terms, PDPA 2010 obligations and the provider's data-handling terms have been considered), and that all AI output will be reviewed by qualified staff.</span>
                    </label>
                    @error('acknowledge')<p class="error">{{ $message }}</p>@enderror
                @endunless
                <div x-show="on" x-cloak>
                    <div class="label">Agents</div>
                    <div class="space-y-2">
                        @foreach ($agents as $key => $agent)
                            <label class="flex items-start gap-3 rounded-md border border-navy-100 p-3 text-sm">
                                <input type="checkbox" name="enabled_agents[]" value="{{ $key }}" class="checkbox mt-0.5" @checked(! in_array($key, $firm->ai_disabled_agents ?? [], true))>
                                <span><span class="font-medium text-navy-900">{{ $agent->name() }}</span>
                                    <span class="block text-xs text-ink-500">{{ collect($agent->tasks())->pluck('label')->join(' · ') }} · tools: {{ implode(', ', $agent->tools()) }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save</button></div>
        </form>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">This month</h2></div>
                <dl class="card-body">
                    <div class="dl-row"><dt>Credits used</dt><dd class="tabular-nums">{{ number_format($used) }} / {{ number_format($allowance) }}</dd></div>
                    <div class="dl-row"><dt>Tokens (in / out)</dt><dd class="tabular-nums">{{ number_format($tokens->i) }} / {{ number_format($tokens->o) }}</dd></div>
                    <div class="dl-row"><dt>1 credit</dt><dd>{{ number_format(config('ai.tokens_per_credit')) }} tokens</dd></div>
                </dl>
                <div class="px-5 pb-4"><div class="h-2 overflow-hidden rounded-full bg-navy-50"><div class="h-full bg-teal-500" style="width: {{ $allowance ? min(100, round($used / $allowance * 100)) : 0 }}%"></div></div>
                    <p class="mt-2 text-xs text-ink-500">Allowance = plan credits per user × billable users.</p></div>
            </div>
        </div>
    </div>
    <div class="card mt-6 overflow-x-auto">
        <div class="card-header"><h2 class="card-title">Recent AI runs</h2></div>
        <table class="table text-sm">
            <thead><tr><th>When</th><th>Engagement</th><th>Task</th><th>By</th><th class="text-right">Credits</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($runs as $r)
                <tr class="row-link"><td class="whitespace-nowrap text-ink-500">{{ local_time($r->created_at) }}</td>
                    <td>{{ $r->engagement->client->name }} <span class="font-mono text-xs text-ink-400">{{ $r->engagement->code }}</span></td>
                    <td><a href="{{ route('ai-runs.show', [$r->engagement, $r]) }}" class="text-navy-900 hover:text-teal-700">{{ str_replace('_', ' ', $r->task) }}</a></td>
                    <td>{{ $r->requester->name }}</td><td class="text-right tabular-nums">{{ $r->credits }}</td>
                    <td><span class="badge badge-dot badge-{{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td></tr>
            @empty
                <tr><td colspan="6"><x-empty title="No AI runs yet" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
