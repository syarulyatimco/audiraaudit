@php $editing = $plan->exists; @endphp
<x-layouts.app :title="$editing ? 'Edit plan' : 'New plan'">
    <x-page-header eyebrow="Superadmin" :title="$editing ? 'Edit '.$plan->name : 'New plan'" />
    <form method="POST" action="{{ $editing ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="card max-w-3xl">
        @csrf @if ($editing) @method('PUT') @endif
        <div class="card-body grid gap-5 sm:grid-cols-2">
            <x-field name="name" label="Name" :value="$plan->name" required />
            <x-field name="code" label="Code" :value="$plan->code" required hint="Lowercase identifier, e.g. standard" />
            <x-field class="sm:col-span-2" name="description" label="Description" :value="$plan->description" />
            <x-field name="price_rm" label="Price per user per month (RM)" type="number" step="0.01" min="0" :value="$plan->exists ? number_format($plan->price_per_user_sen / 100, 2, '.', '') : ''" required />
            <x-field name="min_paid_users" label="Minimum paid users" type="number" min="1" :value="$plan->min_paid_users" required />
            <x-field name="max_users" label="Maximum users" type="number" min="1" :value="$plan->max_users" hint="Leave blank for unlimited." />
            <x-field name="ai_credits_per_user" label="AI credits per user / month" type="number" min="0" :value="$plan->ai_credits_per_user" required hint="Used by AI usage metering (Phase 4)." />
            <x-field name="sort_order" label="Display order" type="number" min="0" :value="$plan->sort_order ?? 0" required />
            <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="checkbox" @checked(old('is_active', $plan->is_active))> Available for new subscriptions</label>
        </div>
        <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3"><a href="{{ route('admin.plans.index') }}" class="btn-secondary">Cancel</a><button class="btn-primary">{{ $editing ? 'Save plan' : 'Create plan' }}</button></div>
    </form>
</x-layouts.app>
