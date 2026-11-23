@props(['name', 'label', 'type' => 'text', 'hint' => null])
@php($id = $attributes->get('id', $name))
<div class="flex flex-col gap-1.5">
    <div class="flex items-baseline justify-between">
        <label for="{{ $id }}" class="text-sm font-semibold">{{ $label }}</label>
        {{ $aside ?? '' }}
    </div>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
        {{ $attributes->except('id')->class(['h-12 w-full rounded-[10px] bg-white px-3.5 text-base text-gray-900 placeholder:text-gray-500 focus:outline-2 focus:outline-offset-2 focus:outline-[#C8102E]', $errors->has($name) ? 'border-2 border-red-700' : 'border border-gray-300']) }}
        @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror>
    @if ($hint)
        <span class="text-xs text-gray-600">{{ $hint }}</span>
    @endif
    @error($name)
        <span id="{{ $id }}-error" class="flex items-center gap-1.5 text-[13px] text-red-700">
            <svg class="flex-none" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
            {{ $message }}
        </span>
    @enderror
</div>
