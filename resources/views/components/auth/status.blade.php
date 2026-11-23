@props(['status' => null, 'tone' => 'success'])
@if ($status)
    <div role="status" {{ $attributes->class(['flex items-start gap-2.5 rounded-[10px] border px-3 py-2.5 text-sm leading-snug', $tone === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-gray-200 bg-gray-50 text-gray-700']) }}>
        <svg class="mt-px flex-none" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            @if ($tone === 'success')<path d="M20 6L9 17l-5-5"></path>@else<circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path>@endif
        </svg>
        <span>{{ $status }}</span>
    </div>
@endif