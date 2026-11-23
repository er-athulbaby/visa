@props(['title', 'description' => null])
<div class="flex flex-col gap-2">
    <h1 class="font-heading text-[28px] leading-[1.15] font-extrabold">{{ $title }}</h1>
    @if ($description)
        <p class="text-[15px] leading-normal text-gray-600">{{ $description }}</p>
    @endif
</div>
