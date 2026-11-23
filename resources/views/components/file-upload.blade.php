@props(['id', 'label', 'hint', 'file' => null])
<div>
    <label for="{{ $id }}" class="flex min-h-24 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-center focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-[#C8102E] hover:border-[#C8102E]">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#B0102A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"></path></svg>
        <span class="text-sm font-semibold text-gray-900">{{ $label }}</span>
        <span class="text-xs text-gray-600">{{ $hint }}</span>
        @if ($file)
            <span class="mt-1 max-w-full truncate rounded-lg bg-white px-3 py-1 text-sm font-semibold text-[#9F1239] ring-1 ring-gray-200">{{ $file->getClientOriginalName() }} · {{ number_format($file->getSize() / 1048576, 1) }} MB</span>
        @else
            <span class="text-sm font-semibold text-[#B0102A]">Choose file</span>
        @endif
        <input id="{{ $id }}" type="file" {{ $attributes->class('sr-only') }}>
    </label>
</div>
