@props(['value'])
@php
    $tones = [
        'prospect' => 'neutral', 'pending_acceptance' => 'warn', 'accepted' => 'success', 'declined' => 'danger', 'inactive' => 'neutral',
        'planning' => 'info', 'fieldwork' => 'teal', 'completion' => 'warn', 'reporting' => 'warn', 'signed_off' => 'success', 'archived' => 'neutral',
        'pending' => 'warn', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral',
        'trial' => 'info', 'active' => 'success', 'suspended' => 'danger', 'trialing' => 'info', 'past_due' => 'danger',
        'deactivated' => 'neutral', 'low' => 'success', 'medium' => 'warn', 'high' => 'danger',
        'draft' => 'neutral', 'pending_approval' => 'warn', 'superseded' => 'neutral', 'not_submitted' => 'warn', 'untested' => 'neutral', 'no_exception' => 'success', 'exception' => 'danger', 'not_started' => 'neutral', 'in_progress' => 'info', 'completed' => 'success', 'selected' => 'teal', 'evaluated' => 'success', 'open' => 'warn', 'resolved' => 'success', 'withdrawn' => 'neutral', 'proposed' => 'warn', 'corrected' => 'success', 'uncorrected' => 'danger', 'issued' => 'info', 'paid' => 'success', 'void' => 'neutral', 'info' => 'info', 'warn' => 'warn', 'danger' => 'danger',
    ];
    $key = $value instanceof \BackedEnum ? $value->value : (string) $value;
    $label = $value instanceof \BackedEnum && method_exists($value, 'label') ? $value->label() : \Illuminate\Support\Str::headline($key);
@endphp
<span {{ $attributes->merge(['class' => 'badge badge-dot badge-'.($tones[$key] ?? 'neutral')]) }}>{{ $label }}</span>
