<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="wsu">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">

        @php($cspNonce = request()->attributes->get('helmet.csp_nonce'))
        {{-- WayneUI's pre-paint theme script: it runs before the first paint, so a
             dark-mode reader never sees a flash of light. It must be inline (a module
             is deferred), so it carries HELMET's per-request nonce. --}}
        <script @if($cspNonce) nonce="{{ $cspNonce }}" @endif>
            (function () {
                try {
                    var stored = localStorage.getItem('wsu-theme');
                    var legacy = localStorage.getItem('appearance');
                    if (stored !== 'wsu' && stored !== 'wsu-dark' && (legacy === 'light' || legacy === 'dark')) {
                        stored = legacy === 'dark' ? 'wsu-dark' : 'wsu';
                    }
                    var dark = stored === 'wsu-dark' || (stored !== 'wsu' && matchMedia('(prefers-color-scheme: dark)').matches);
                    var root = document.documentElement;
                    root.setAttribute('data-theme', dark ? 'wsu-dark' : 'wsu');
                    root.classList.add(dark ? 'dark' : 'light');
                    root.style.colorScheme = dark ? 'dark' : 'light';
                } catch (e) {}
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        @php(\Illuminate\Support\Facades\Vite::useCspNonce($cspNonce))
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
