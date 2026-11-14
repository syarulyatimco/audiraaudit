<x-layouts.app :title="$library->name">
    <x-page-header :title="$library->name" :eyebrow="$library->isPlatform() ? 'AUDIRA template · read-only' : 'Firm methodology'" :subtitle="$library->basedOn ? 'Copied from '.$library->basedOn->name : null">
        <x-slot:actions>
            <a href="{{ route('methodology.index') }}" class="btn-ghost btn-sm">← All libraries</a>
            @if ($canManage)
                <form method="POST" action="{{ route('methodology.copy', $library) }}" class="flex items-center gap-2" x-data>
                    @csrf
                    <input type="text" name="name" class="input !py-1.5 text-xs" value="{{ old('name', ($library->isPlatform() ? auth()->user()->firm->name.' methodology' : $library->name.' (copy)')) }}" required maxlength="160" aria-label="Name of the copy">
                    <button class="btn-primary btn-sm whitespace-nowrap">Make a firm copy</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>
    @error('name')<p class="error -mt-4 mb-4">{{ $message }}</p>@enderror
    @error('library')<p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($library->description)<p class="text-sm text-ink-600">{{ $library->description }}</p>@endif
            @foreach (\App\Enums\ProgrammeStage::cases() as $stage)
                @php $list = $programmes->get($stage->value, collect()); @endphp
                <div class="card overflow-x-auto">
                    <div class="card-header"><h2 class="card-title">{{ $stage->label() }}</h2><span class="text-xs text-ink-500">{{ $list->count() }} programme(s)</span></div>
                    @if ($list->isEmpty())
                        <p class="px-5 py-4 text-sm text-ink-500">None.</p>
                    @else
                        <table class="table">
                            <thead><tr><th>Ref</th><th>Programme</th><th>Routed to</th><th class="text-right">Steps</th></tr></thead>
                            <tbody>
                            @foreach ($list as $p)
                                <tr class="row-link">
                                    <td class="font-mono text-xs font-semibold">{{ $p->ref_prefix }}</td>
                                    <td><a href="{{ route('methodology.programmes.show', [$library, $p]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $p->title }}</a></td>
                                    <td class="text-xs text-ink-600">{{ $p->routing() }}</td>
                                    <td class="text-right tabular-nums">{{ $p->steps_count }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="space-y-6">
            @if ($canEdit)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Library details</h2></div>
                    <form method="POST" action="{{ route('methodology.update', $library) }}" class="card-body space-y-3">
                        @csrf @method('PUT')
                        <x-field name="name" label="Name" :value="$library->name" required />
                        <x-field name="description" label="Description" type="textarea" rows="4" :value="$library->description" />
                        <button class="btn-primary btn-sm">Save</button>
                    </form>
                    <form method="POST" action="{{ route('methodology.archive', $library) }}" class="border-t border-navy-100/70 px-5 py-3" onsubmit="return confirm('Archive this library? Engagements using it keep their programmes; it can no longer be chosen or edited.')">
                        @csrf
                        <button class="btn-danger btn-sm">Archive library</button>
                        @if ($inUse)<p class="hint">Used by {{ $inUse }} engagement(s).</p>@endif
                    </form>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Add programme</h2></div>
                    @include('methodology.programme-form', ['programme' => null, 'action' => route('methodology.programmes.store', $library), 'method' => 'POST'])
                </div>
            @else
                <div class="card card-body text-sm text-ink-600">
                    @if ($library->isPlatform())
                        This template is maintained by AUDIRA and is read-only. @if ($canManage) Make a firm copy to change programmes, steps or routing. @else Ask a partner or firm admin to make a firm copy if changes are needed. @endif
                    @elseif ($library->status === 'archived')
                        This library is archived. Engagements that used it keep their programmes.
                    @else
                        Partners and firm admins maintain firm libraries.
                    @endif
                </div>
            @endif
            <div class="card card-body space-y-1 text-xs text-ink-600">
                <p class="font-semibold text-navy-900">How routing works</p>
                <p><span class="font-medium">Whole engagement</span> — one sheet per engagement (planning).</p>
                <p><span class="font-medium">FSLI codes</span> — one sheet, attached to the first listed code with mapped accounts.</p>
                <p><span class="font-medium">Classification</span> — one sheet for every FSLI of that classification that has no code-specific programme in the same stage.</p>
            </div>
        </div>
    </div>
</x-layouts.app>
