@props(['name', 'label', 'options' => [], 'value' => null, 'placeholder' => null, 'hint' => null, 'required' => false])
@php $current = (string) old($name, $value instanceof \BackedEnum ? $value->value : $value); @endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="label">{{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
    <select id="{{ $name }}" name="{{ $name }}" @if ($required) required @endif {{ $attributes->except('class')->merge(['class' => 'input'.($errors->has($name) ? ' input-error' : '')]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $key => $optionLabel)
            <option value="{{ $key }}" @selected($current === (string) $key)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($hint)<p class="hint">{{ $hint }}</p>@endif
    @error($name)<p class="error">{{ $message }}</p>@enderror
</div>
