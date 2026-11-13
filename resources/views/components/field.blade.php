@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false])
<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="label">{{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
    @if ($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $attributes->get('rows', 4) }}" @if ($required) required @endif
            {{ $attributes->except(['class', 'rows'])->merge(['class' => 'input'.($errors->has($name) ? ' input-error' : '')]) }}>{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" @if ($required) required @endif
            {{ $attributes->except('class')->merge(['class' => 'input'.($errors->has($name) ? ' input-error' : '')]) }}>
    @endif
    @if ($hint)<p class="hint">{{ $hint }}</p>@endif
    @error($name)<p class="error">{{ $message }}</p>@enderror
</div>
