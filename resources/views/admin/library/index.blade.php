<x-layouts.app title="Audit library">
    <x-page-header eyebrow="Superadmin" title="Audit library" subtitle="Load content only from authorised source material. Never paraphrase or invent paragraphs: firms rely on this text in their audit files.">
        <x-slot:actions><button type="button" class="btn-primary" @click="$dispatch('open-modal', 'framework')">New framework</button></x-slot:actions>
    </x-page-header>
    <div class="space-y-6">
        @foreach ($frameworks as $fw)
            <div class="card" x-data="{ adding: false }">
                <div class="card-header">
                    <div><h2 class="card-title">{{ $fw->name }} <span class="ml-1 font-mono text-2xs text-ink-400">{{ $fw->code }}</span></h2><p class="text-xs text-ink-500">{{ $fw->category->label() }}{{ $fw->issuer ? ' · '.$fw->issuer : '' }}</p></div>
                    <button type="button" class="btn-secondary btn-sm" @click="adding = !adding">Add standard</button>
                </div>
                <form x-show="adding" x-cloak method="POST" action="{{ route('admin.library.standards.store', $fw) }}" class="flex flex-wrap items-end gap-3 border-b border-navy-50 px-5 py-3">
                    @csrf
                    <div class="w-40"><label class="label">Reference</label><input name="reference" class="input" placeholder="e.g. ISA 320" required></div>
                    <div class="min-w-[16rem] flex-1"><label class="label">Title</label><input name="title" class="input" required></div>
                    <button class="btn-primary">Add</button>
                </form>
                <table class="table">
                    <thead><tr><th>Standard</th><th>Versions</th><th class="w-72">New version</th></tr></thead>
                    <tbody>
                    @forelse ($fw->standards as $st)
                        <tr>
                            <td><span class="font-semibold text-navy-900">{{ $st->reference }}</span> <span class="text-ink-600">{{ $st->title }}</span></td>
                            <td class="space-x-1">
                                @forelse ($st->versions as $v)
                                    <a href="{{ route('admin.library.versions.show', $v) }}" class="badge badge-{{ ['draft' => 'warn', 'published' => 'success', 'superseded' => 'neutral'][$v->status->value] }}">{{ $v->version_label }}</a>
                                @empty
                                    <span class="text-xs text-ink-400">None</span>
                                @endforelse
                            </td>
                            <td x-data="{ open: false }">
                                <button type="button" class="btn-ghost btn-sm" @click="open = !open">+ Draft version</button>
                                <form x-show="open" x-cloak method="POST" action="{{ route('admin.library.versions.store', $st) }}" enctype="multipart/form-data" class="mt-2 space-y-2">
                                    @csrf
                                    <input name="version_label" class="input py-1.5 text-xs" placeholder="Version label (e.g. 2024 Handbook)" required>
                                    <input name="source_title" class="input py-1.5 text-xs" placeholder="Authorised source title" required>
                                    <input name="source_url" type="url" class="input py-1.5 text-xs" placeholder="https:// source URL (optional)">
                                    <input name="effective_date" type="date" class="input py-1.5 text-xs">
                                    <input name="source_file" type="file" class="block w-full text-xs">
                                    <button class="btn-primary btn-sm">Create draft</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-sm text-ink-500">No standards.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
    <x-modal name="framework" title="New framework">
        <form method="POST" action="{{ route('admin.library.frameworks.store') }}" class="space-y-4">
            @csrf
            <x-field name="name" label="Name" required />
            <x-field name="code" label="Code" required hint="lowercase, e.g. isa" />
            <x-select name="category" label="Category" :options="\App\Enums\LibraryCategory::options()" required />
            <x-field name="issuer" label="Issuer" />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button class="btn-primary">Create</button></div>
        </form>
    </x-modal>
</x-layouts.app>
