<x-layouts.app :title="$programme->ref_prefix.' · '.$library->name">
    @php
        $base = route('methodology.steps.update', [$library, $programme, '__ID__']);
        $config = [
            'rows' => $programme->steps->map(fn ($s) => \App\Http\Controllers\Methodology\MethodologyController::stepJson($s))->values(),
            'columns' => [
                ['key' => 'ref', 'label' => 'Ref', 'editable' => true, 'width' => '5rem'],
                ['key' => 'procedure', 'label' => 'Procedure', 'editable' => true, 'width' => '30rem'],
                ['key' => 'assertions', 'label' => 'Assertion', 'editable' => true, 'width' => '7rem'],
                ['key' => 'isa_refs', 'label' => 'ISA reference', 'editable' => true, 'width' => '11rem'],
                ['key' => 'guidance', 'label' => 'Guidance', 'editable' => true, 'width' => '20rem'],
            ],
            'custom' => [],
            'canEdit' => $canEdit, 'canManage' => $canEdit,
            'urls' => ['row' => $base, 'move' => $base.'/move', 'add' => route('methodology.steps.store', [$library, $programme])],
        ];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('methodology.show', $library) }}" class="text-sm text-ink-500 hover:text-navy-900">← {{ $library->name }}</a>
        <span class="text-ink-300">/</span>
        <span class="font-mono text-sm font-semibold text-navy-800">{{ $programme->ref_prefix }}</span>
        <span class="text-sm font-medium text-navy-900">{{ $programme->title }}</span>
        <span class="badge badge-neutral">{{ $programme->stage->label() }}</span>
        <span class="text-xs text-ink-500">{{ $programme->routing() }}</span>
        @unless ($canEdit)<span class="badge badge-teal">Read-only</span>@endunless
    </div>
    @if ($programme->objective)<p class="mb-4 max-w-4xl text-sm text-ink-600"><span class="font-semibold text-navy-900">Objective.</span> {{ $programme->objective }}</p>@endif
    @error('file')<p class="mb-3 error">{{ $message }}</p>@enderror

    <x-xl-grid :config="$config" :show-guidance="false">
        <x-slot:toolbar>
            <a href="{{ route('methodology.programmes.export', [$library, $programme]) }}" class="btn-secondary btn-sm">Download .xlsx</a>
            @if ($canEdit)<button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('library-upload').click()">Upload .xlsx</button>@endif
        </x-slot:toolbar>
    </x-xl-grid>

    @if ($canEdit)
        <form method="POST" action="{{ route('methodology.programmes.import', [$library, $programme]) }}" enctype="multipart/form-data" class="hidden">
            @csrf <input type="file" name="file" accept=".xlsx" id="library-upload" onchange="this.form.submit()">
        </form>
        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                <div class="card-header"><h2 class="card-title">Programme settings</h2></div>
                @include('methodology.programme-form', ['programme' => $programme, 'action' => route('methodology.programmes.update', [$library, $programme]), 'method' => 'PUT'])
            </div>
            <div class="card card-body text-sm">
                <p class="text-ink-600">Changes apply to engagements that choose this library from now on. Engagements already using it keep the programmes they were given.</p>
                <form method="POST" action="{{ route('methodology.programmes.destroy', [$library, $programme]) }}" class="mt-4" onsubmit="return confirm('Remove this programme from the library?')">
                    @csrf @method('DELETE') <button class="btn-danger btn-sm">Remove programme</button>
                </form>
            </div>
        </div>
    @endif
</x-layouts.app>
