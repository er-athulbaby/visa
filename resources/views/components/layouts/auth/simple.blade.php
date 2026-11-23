<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-svh bg-white font-sans text-gray-900 antialiased">
        <div class="h-1.5 bg-[#C8102E]"></div>
        <div class="mx-auto flex min-h-[calc(100svh-6px)] w-full max-w-md flex-col">
            {{ $slot }}
        </div>
        @fluxScripts
    </body>
</html>
