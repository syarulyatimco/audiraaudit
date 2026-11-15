{{-- File source: upload, or a document already on the engagement (e.g. uploaded by the client in the portal). --}}
<div x-data="{ src: 'upload' }" class="space-y-3">
    <div class="inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-xs">
        <button type="button" @click="src = 'upload'" :class="src === 'upload' ? 'bg-navy-800 text-white' : 'text-ink-600'" class="rounded px-2.5 py-1 font-medium">Upload a file</button>
        <button type="button" @click="src = 'document'" :class="src === 'document' ? 'bg-navy-800 text-white' : 'text-ink-600'" class="rounded px-2.5 py-1 font-medium">From engagement documents</button>
    </div>
    <div x-show="src === 'upload'"><input type="file" name="file" accept=".csv,.xlsx,.txt" class="block w-full text-sm" :disabled="src !== 'upload'"></div>
    <div x-show="src === 'document'" x-cloak>
        <select name="document" class="input" :disabled="src !== 'document'">
            <option value="">— choose —</option>
            @foreach ($documents as $d)<option value="{{ $d->uuid }}">{{ $d->title }}</option>@endforeach
        </select>
        <p class="hint">Includes files the client uploaded through the portal. CSV or XLSX only.</p>
    </div>
    @error('file')<p class="error">{{ $message }}</p>@enderror
</div>
