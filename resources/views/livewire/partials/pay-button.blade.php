<div class="order-3 sticky bottom-0 z-10 flex items-center justify-between gap-3 rounded-b-2xl border-t border-gray-200 bg-white px-4 py-3 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] lg:order-2 lg:static lg:justify-end lg:border-b lg:px-5 lg:shadow-none">
    <span class="text-sm text-gray-700" aria-live="polite"><strong>{{ $sel['count'] }} selected</strong> · {{ $sel['total'] }}</span>
    <button type="button" wire:click="pay({{ $event->id }})" wire:loading.attr="disabled" @disabled($sel['count'] === 0) class="h-12 rounded-xl bg-[#C8102E] px-6 text-[15px] font-bold text-white hover:bg-[#B0102A] disabled:cursor-not-allowed disabled:opacity-60">Pay now</button>
</div>
