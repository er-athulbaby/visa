@props(['name' => true])
<div class="flex items-center gap-3 px-6 pt-6">
    <img src="{{ asset('images/boc-logo.png') }}" alt="Bahrain Olympic Committee" class="h-16 w-auto">
    @if ($name)
        <div class="flex flex-col gap-0.5">
            <span class="font-heading text-[15px] font-extrabold">Bahrain Olympic Committee</span>
            <span class="text-[13px] text-gray-600">Event Visa Portal</span>
        </div>
    @endif
</div>
