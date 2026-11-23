<div class="mt-2 text-right">
    <button wire:click="pay({{ $event->id }})" wire:loading.attr="disabled" class="rounded bg-red-700 px-4 py-2 text-white">
        Pay visa fee for selected ({{ $event->currency }} {{ number_format($event->visa_fee, 3) }} each)
    </button>
</div>
