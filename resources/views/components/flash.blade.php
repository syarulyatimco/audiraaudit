@if (session('status'))
    <div x-data="{ show: true }" x-show="show" class="mb-6 flex items-start gap-3 rounded-md border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-teal-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <div class="flex-1">{{ session('status') }}</div>
        <button type="button" class="text-teal-700 hover:text-teal-900" @click="show = false" aria-label="Dismiss">&times;</button>
    </div>
@endif
@if ($errors->any())
    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <div class="font-semibold">Please review the following:</div>
        <ul class="mt-1 list-inside list-disc space-y-0.5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
