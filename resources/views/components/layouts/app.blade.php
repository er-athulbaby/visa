@php
    $user = auth()->user();
    $items = [
        ['route' => 'dashboard', 'active' => request()->routeIs('dashboard'), 'label' => 'Dashboard', 'icon' => '<rect x="3" y="3" width="7" height="9" rx="1.5"></rect><rect x="14" y="3" width="7" height="5" rx="1.5"></rect><rect x="14" y="12" width="7" height="9" rx="1.5"></rect><rect x="3" y="16" width="7" height="5" rx="1.5"></rect>'],
        ['route' => 'settings.profile', 'active' => request()->routeIs('settings.*'), 'label' => 'Settings', 'icon' => '<circle cx="12" cy="12" r="3"></circle><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"></path>'],
    ];
    $link = 'flex min-h-11 items-center gap-2.5 rounded-[10px] px-3 text-sm';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#F6F6F7] font-sans text-gray-900">
        {{-- Desktop sidebar --}}
        <aside class="fixed inset-y-0 left-0 z-20 hidden w-64 flex-col gap-7 border-r border-gray-200 bg-white p-4 lg:flex">
            <div class="flex flex-col items-start gap-3 px-2">
                <img src="{{ asset('images/boc-logo.png') }}" alt="Bahrain Olympic Committee" class="h-[72px] w-auto">
                <div class="flex flex-col">
                    <span class="font-heading text-[15px] font-extrabold">Bahrain Olympic Committee</span>
                    <span class="text-[13px] text-gray-600">Event Visa Portal</span>
                </div>
            </div>

            <nav aria-label="Main" class="flex flex-col gap-1">
                @foreach ($items as $i)
                    <a href="{{ route($i['route']) }}" wire:navigate @if ($i['active']) aria-current="page" @endif
                       class="{{ $link }} {{ $i['active'] ? 'bg-[#FEF2F2] font-bold text-[#9F1239]' : 'font-medium text-gray-700 hover:bg-gray-50' }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $i['icon'] !!}</svg>
                        {{ $i['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="mt-auto flex items-center gap-2.5 border-t border-gray-200 px-2 pt-4">
                <span aria-hidden="true" class="flex size-10 flex-none items-center justify-center rounded-full bg-gray-100 text-sm font-bold">{{ $user->initials() }}</span>
                <div class="flex min-w-0 flex-col">
                    <span class="truncate text-sm font-semibold">{{ $user->name }}</span>
                    <span class="truncate text-xs text-gray-600">{{ $user->email }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                    @csrf
                    <button type="submit" aria-label="Log out" title="Log out" class="flex size-11 items-center justify-center rounded-[10px] text-gray-600 hover:bg-gray-50">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"></path></svg>
                    </button>
                </form>
            </div>
        </aside>

        {{-- Mobile top bar --}}
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-2 lg:hidden">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2.5">
                <img src="{{ asset('images/boc-logo.png') }}" alt="Bahrain Olympic Committee" class="h-9 w-auto">
                <span class="font-heading text-[15px] font-extrabold">Visa Portal</span>
            </a>
            <details class="relative ml-auto" x-data x-on:keydown.escape="$el.open = false" x-on:click.outside="$el.open = false">
                <summary aria-label="Account menu" class="flex size-11 cursor-pointer list-none items-center justify-center rounded-full bg-gray-100 text-sm font-bold [&::-webkit-details-marker]:hidden">{{ $user->initials() }}</summary>
                <div class="absolute right-0 mt-2 flex w-60 flex-col gap-1 rounded-2xl border border-gray-200 bg-white p-2 shadow-lg">
                    <div class="flex flex-col border-b border-gray-100 px-3 pb-2">
                        <span class="truncate text-sm font-semibold">{{ $user->name }}</span>
                        <span class="truncate text-xs text-gray-600">{{ $user->email }}</span>
                    </div>
                    @foreach ($items as $i)
                        <a href="{{ route($i['route']) }}" wire:navigate @if ($i['active']) aria-current="page" @endif
                           class="{{ $link }} {{ $i['active'] ? 'bg-[#FEF2F2] font-bold text-[#9F1239]' : 'font-medium text-gray-700' }}">{{ $i['label'] }}</a>
                    @endforeach
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="{{ $link }} w-full font-medium text-gray-700">Log out</button>
                    </form>
                </div>
            </details>
        </header>

        <main class="lg:pl-64">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
