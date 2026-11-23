<div class="flex items-start gap-6 max-md:flex-col">
    <nav aria-label="Settings" class="flex w-full gap-1 md:w-[200px] md:flex-col">
        @foreach (['settings.profile' => 'Profile', 'settings.password' => 'Password'] as $route => $label)
            <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs($route)) aria-current="page" @endif
               class="flex min-h-11 items-center rounded-[10px] px-3 text-sm {{ request()->routeIs($route) ? 'bg-[#FEF2F2] font-bold text-[#9F1239]' : 'font-medium text-gray-700 hover:bg-gray-50' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <div class="w-full max-w-lg flex-1 rounded-2xl border border-gray-200 bg-white p-5">
        <h2 class="font-heading text-lg font-extrabold">{{ $heading ?? '' }}</h2>
        <p class="text-sm text-gray-600">{{ $subheading ?? '' }}</p>

        <div class="mt-5 w-full">
            {{ $slot }}
        </div>
    </div>
</div>